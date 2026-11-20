<?php

declare(strict_types=1);

namespace App\Console;

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PrintJobStatus;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\ReasonStatus;
use App\Modules\Shifts\Enums\ShiftStatus;
use BackedEnum;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The status machines of the API as the web app reads them (ADR 0032): apps/web/src/lib/api/stateMachines.ts is this class's output, so
 * the web never types a status list or a "may this go there" of its own. Where a screen has the record at hand it reads the record's
 * `actions`; this mirror is for the few places that decide without one (the outbox, which only knows the order it sent, asks which
 * statuses come after `sent`).
 *
 * For each machine: its values in the enum's order, its transitions(), every status reachable from each one (the transitive closure),
 * the terminal ones, and every yes/no question the enum answers about a status (a public method is*() or has*() without arguments, such
 * as ShiftStatus::isRunning), as the list of statuses that answer yes. `php artisan bakery:state-machines` writes the file;
 * tests/Unit/StateMachinesMirrorTest.php fails when the committed file is not this output.
 */
final class StateMachines
{
    /** @var array<string, class-string<BackedEnum>> the web's name => the enum */
    public const MACHINES = [
        'order' => OrderStatus::class,
        'order_line' => LineStatus::class,
        'payment' => PaymentStatus::class,
        'print_job' => PrintJobStatus::class,
        'transfer' => TransferStatus::class,
        'purchase' => PurchaseStatus::class,
        'production_request' => RequestStatus::class,
        'production_batch' => BatchStatus::class,
        'shift' => ShiftStatus::class,
        'count' => CountStatus::class,
        'count_reason' => ReasonStatus::class,
    ];

    /** The web file this class writes, from apps/api. */
    public const TARGET = '../web/src/lib/api/stateMachines.ts';

    /**
     * @return array<string, array{values: list<string>, transitions: array<string, list<string>>, reachable: array<string, list<string>>, terminal: list<string>, is: array<string, list<string>>}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::MACHINES as $name => $enum) {
            $out[$name] = self::machine($enum);
        }

        return $out;
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array{values: list<string>, transitions: array<string, list<string>>, reachable: array<string, list<string>>, terminal: list<string>, is: array<string, list<string>>}
     */
    private static function machine(string $enum): array
    {
        /** @var list<BackedEnum> $cases */
        $cases = $enum::cases();
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        /** @var array<string, list<BackedEnum>> $table every machine has the StateMachine trait's transitions() */
        $table = (new ReflectionMethod($enum, 'transitions'))->invoke(null);
        $transitions = [];
        foreach ($values as $value) {
            $transitions[$value] = array_map(static fn (BackedEnum $to): string => (string) $to->value, $table[$value] ?? []);
        }

        $reachable = [];
        foreach ($values as $from) {
            $seen = [];
            $todo = $transitions[$from];
            while ($todo !== []) {
                $next = array_shift($todo);
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    array_push($todo, ...$transitions[$next]);
                }
            }
            // in the enum's order, so the file reads the same way every time
            $reachable[$from] = array_values(array_filter($values, static fn (string $v): bool => isset($seen[$v])));
        }

        $is = [];
        foreach ((new \ReflectionClass($enum))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            // isTerminal() is the StateMachine trait's own, already the `terminal` list
            if ($method->isStatic() || $method->getNumberOfParameters() > 0 || preg_match('/^(is|has)[A-Z]/', $method->getName()) !== 1 || $method->getName() === 'isTerminal'
                || ! $type instanceof ReflectionNamedType || $type->getName() !== 'bool') {
                continue;
            }
            $name = $method->getName();
            $is[$name] = array_values(array_map(
                static fn (BackedEnum $case): string => (string) $case->value,
                array_filter($cases, static fn (BackedEnum $case): bool => $case->{$name}() === true),
            ));
        }
        ksort($is);

        return [
            'values' => $values,
            'transitions' => $transitions,
            'reachable' => $reachable,
            'terminal' => array_values(array_filter($values, static fn (string $v): bool => $transitions[$v] === [])),
            'is' => $is,
        ];
    }

    /** The TypeScript file: the machines as one const, and their value types. */
    public static function typescript(): string
    {
        // an enum with no yes/no question still has an `is` object, never a list
        $machines = array_map(static fn (array $m): array => array_replace($m, ['is' => (object) $m['is']]), self::all());
        $json = json_encode($machines, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // two spaces a level, as the web's own files are indented
        $json = (string) preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
        $types = '';
        foreach (array_keys(self::MACHINES) as $name) {
            $type = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
            $types .= "export type {$type}State = (typeof STATE_MACHINES)[\"{$name}\"][\"values\"][number];\n";
        }

        return "// Generated by `php artisan bakery:state-machines` from the API's status enums (ADR 0032). Do not edit: change the enum\n"
            ."// and run the command; tests/Unit/StateMachinesMirrorTest.php fails while this file is not its output.\n\n"
            .'export const STATE_MACHINES = '.$json." as const;\n\n"
            .$types;
    }
}
