<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\EnrolDevice;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoSeedRefused;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Phase 5 of the demo bakery (phase-5 plan section 8), composed by DemoSeeder after Phase4Seeder when SEED_PHASE is 5 or unset.
 *
 * Built so far (step 5.5d): with LOAD_DEVICES=N (1 to 99) it enrols load-01 to load-N in the demo branch for the perf-load CI job, each
 * through EnrolDevice as the demo admin (audited `device.enrol`, as POST /devices does), the kinds in the order of LOAD_MIX: 34 pads, 12
 * KDS, 8 tills and 6 admin and shift screens for N=60 (tests/Fixtures/perf/poll-profile.json, mix `ci`; PollBudgetTest keeps the two
 * equal), any device past those a tablet. A device already enrolled under its fingerprint is left as it is, so a second run writes
 * nothing. Without LOAD_DEVICES it writes nothing at all.
 *
 * TODO(p5) step 5.6b: enrol the demo branch's pad, KDS, till and bridge (fixed fingerprints demo-pad-1, demo-kds-1, demo-till-1,
 * demo-bridge-1; the bridge's token made at random and never printed) and set the receipt settings' sample TIN and paper width 80.
 *
 * It never writes a stock movement, an order or a payment, so no earlier gate's figures move; the live host is never seeded by a deploy.
 */
final class Phase5Seeder extends Seeder
{
    /**
     * The devices of perf-load in fingerprint order: [kind, how many]. Pads, then the kitchen and bar displays, then the tills, then the
     * admin board and the shift screens (tablets too).
     *
     * @var list<array{0: string, 1: int}>
     */
    public const array LOAD_MIX = [['tablet', 34], ['kds', 12], ['till', 8], ['tablet', 6]];

    private const string SOURCE_URL = 'https://bakery-ops.orcamind.dev/demo-seed';

    public function run(): void
    {
        $count = self::loadDevices();
        if ($count === 0) {
            return;
        }

        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();
        $current = app(CurrentBranch::class);
        $actor = app(ActorContext::class);
        $previousBranch = $current->isResolved() ? $current->get() : null;
        $previousActor = $actor->toArray();

        try {
            DB::transaction(function () use ($branch, $current, $actor, $count): void {
                $current->set($branch);
                $admin = User::query()->withTrashed()->where('phone', DemoBakery::ADMIN_PHONE)->firstOrFail();
                $actor->reset();
                $actor->authenticate($admin->id);
                $this->loadDevicesIn($branch, $count);
            });
        } finally {
            $this->restore($previousBranch, $previousActor);
        }
    }

    /**
     * The kinds of load-01 to load-N, in order.
     *
     * @return list<string>
     */
    public static function loadDeviceKinds(int $count): array
    {
        $kinds = [];
        foreach (self::LOAD_MIX as [$kind, $many]) {
            array_push($kinds, ...array_fill(0, $many, $kind));
        }
        $kinds = array_slice($kinds, 0, max(0, $count));
        while (count($kinds) < $count) {
            $kinds[] = DeviceKind::default()->value;
        }

        return $kinds;
    }

    /** LOAD_DEVICES as a count, 0 when it is not set. */
    private static function loadDevices(): int
    {
        $value = getenv('LOAD_DEVICES');
        if ($value === false || trim($value) === '') {
            return 0;
        }
        if (preg_match('/^[1-9][0-9]?$/', trim($value)) !== 1) {
            throw new InvalidArgumentException("LOAD_DEVICES must be a count of devices from 1 to 99, not \"{$value}\".");
        }

        return (int) trim($value);
    }

    private function loadDevicesIn(Branch $branch, int $count): void
    {
        $enrol = app(EnrolDevice::class);
        foreach (self::loadDeviceKinds($count) as $index => $kind) {
            $fingerprint = sprintf('load-%02d', $index + 1);
            if (Device::query()->withoutGlobalScopes()->where('fingerprint', $fingerprint)->exists()) {
                continue;
            }
            $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, self::SOURCE_URL."/{$branch->id}/device/{$fingerprint}")->toString();
            $enrol->handle($uuid, sprintf('Load test device %02d', $index + 1), $fingerprint, $kind, null);
        }
    }

    /** @param  array{user_id: int|null, acting_user_id: int|null, shift_instance_id: int|null, station_id: int|null, device_id: int|null}  $actor */
    private function restore(?Branch $branch, array $actor): void
    {
        $current = app(CurrentBranch::class);
        $branch === null ? $current->clear() : $current->set($branch);

        $context = app(ActorContext::class);
        $context->reset();
        if ($actor['user_id'] !== null) {
            $context->authenticate($actor['user_id']);
        }
        $context->actingAs($actor['acting_user_id'])
            ->inShift($actor['shift_instance_id'])
            ->atStation($actor['station_id'])
            ->onDevice($actor['device_id']);
    }
}
