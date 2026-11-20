<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The proof behind a record's `actions` list (ADR 0032): for every state a record can be in and every person who may look at it, the
 * list the API gives that person names exactly the endpoints that would accept that person's write now and change the record.
 *
 * Each record is built inside a savepoint, each endpoint is called inside a savepoint of its own and rolled back, so every call sees the
 * record as the list described it. An endpoint "accepts" when it answers 2xx and the record's fingerprint changed: a repeat (200, nothing
 * written) is not an action, and is not listed. A 5xx is always a mismatch. Every disagreement is collected, so one run names them all.
 *
 * @template TRecord of object
 */
final class ActionsMatrix
{
    /**
     * The refusals that say what the person must do first, not what the record is (ADR 0032): a listed action may be refused with one
     * of these, and the screen shows the notice the refusal carries ("start a shift first").
     */
    private const SITUATION = ['payments.shift.required', 'inventory.shift.required'];

    /**
     * @param  array<string, Closure(): TRecord>  $records  label => builds a fresh record in that state
     * @param  array<string, User>  $people  label => the person who looks and acts (logged in for both)
     * @param  array<string, Closure(TRecord, User): (TestResponse|null)>  $calls  action => the endpoint call, as the logged-in person; null when
     *                                                                             the record has nothing that action could be called on
     * @param  Closure(TRecord, User): list<string>  $listed  the record's `actions`, read through the API as the logged-in person
     * @param  Closure(TRecord): mixed  $fingerprint  what the record is now: an accepted write changes it
     * @param  (Closure(User): void)|null  $login  how a person is logged in (default loginAs)
     * @return list<string> every disagreement: "<record> / <person> / <action>: listed|not listed, endpoint <status> (changed|unchanged)"
     */
    public static function mismatches(array $records, array $people, array $calls, Closure $listed, Closure $fingerprint, ?Closure $login = null): array
    {
        $login ??= static function (User $user): void {
            loginAs($user);
        };
        $out = [];
        foreach ($records as $state => $build) {
            foreach ($people as $who => $person) {
                DB::beginTransaction();
                try {
                    $record = $build();
                    $login($person);
                    $names = $listed($record, $person);
                    foreach ($calls as $action => $call) {
                        DB::beginTransaction();
                        try {
                            $before = json_encode($fingerprint($record));
                            $response = $call($record, $person);
                            $status = $response?->getStatusCode();
                            $changed = $response !== null && json_encode($fingerprint($record)) !== $before;
                            $accepted = $status !== null && $status >= 200 && $status < 300 && $changed;
                        } finally {
                            DB::rollBack();
                        }
                        $isListed = in_array($action, $names, true);
                        // a listed move refused for the person's situation rather than the record (no shift running for the till's
                        // cash) is what the list promises: the record allows it, the screen tells the person what to do first
                        $situation = $isListed && ! $accepted && in_array(self::key($response), self::SITUATION, true);
                        if ($status !== null && $status >= 500) {
                            $out[] = "{$state} / {$who} / {$action}: endpoint {$status} ".self::key($response);
                        } elseif ($accepted !== $isListed && ! $situation) {
                            $out[] = sprintf('%s / %s / %s: %s, endpoint %s (%s) %s', $state, $who, $action, $isListed ? 'listed' : 'not listed',
                                $status ?? 'not called', $changed ? 'changed' : 'unchanged', self::key($response));
                        }
                    }
                } finally {
                    DB::rollBack();
                }
            }
        }

        return $out;
    }

    private static function key(?TestResponse $response): string
    {
        if ($response === null) {
            return '';
        }
        $key = $response->json('message_key');

        return is_string($key) ? $key : '';
    }
}
