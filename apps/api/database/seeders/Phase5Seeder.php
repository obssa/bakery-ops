<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\EnrolDevice;
use App\Modules\Payments\Actions\SaveReceiptSettings;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\Demo\DemoSeedRefused;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Phase 5 of the demo bakery (phase-5 plan section 8), composed by DemoSeeder after Phase4Seeder when SEED_PHASE is 5 or unset. In the
 * demo branch only:
 *
 * - the shop's four kinds of device (step 5.6b), DEMO_DEVICES under fixed fingerprints: a pad, a kitchen display at the kitchen, a till
 *   at the till and a print bridge. Each is enrolled through EnrolDevice as the demo admin (audited `device.enrol`, as POST /devices
 *   does); the bridge's token is made at random there and dropped here, never printed or stored but as its sha256;
 * - the receipts' sample TIN and 80 mm paper (step 5.6b): when the branch's receipt settings have no TIN (or no row), they are saved
 *   through SaveReceiptSettings with SAMPLE_TIN and paper_width_mm 80, the rest kept; a TIN already typed is the shop's own and is left;
 * - with LOAD_DEVICES=N (1 to 99, step 5.5d) load-01 to load-N for the perf-load CI job, the kinds in the order of LOAD_MIX: 34 pads, 12
 *   KDS, 8 tills and 6 admin and shift screens for N=60 (tests/Fixtures/perf/poll-profile.json, mix `ci`; PollBudgetTest keeps the two
 *   equal), any device past those a tablet.
 *
 * A device already enrolled under its fingerprint is left as it is, so a second run writes nothing. In production it writes nothing at
 * all: the live host is never seeded (10.2 D5), and its demo branch gets no devices or settings from here. It never writes a stock
 * movement, an order or a payment, so no earlier gate's figures move.
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

    /**
     * The demo branch's devices, in the order they are enrolled: fingerprint => [name, kind, the code of its station or null].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    public const array DEMO_DEVICES = [
        'demo-pad-1' => ['Demo pad 1', 'tablet', null],
        'demo-kds-1' => ['Demo kitchen display 1', 'kds', 'kitchen'],
        'demo-till-1' => ['Demo till 1', 'till', 'till'],
        'demo-bridge-1' => ['Demo print bridge 1', 'bridge', null],
    ];

    /** A made-up TIN of the right length (ten digits), mostly zeros so it can be no one's; the same as Phase4Seeder's demo receipts. */
    public const string SAMPLE_TIN = '0000000100';

    /** The demo's receipt printer prints on 80 mm paper (576 dots). */
    public const int PAPER_WIDTH_MM = 80;

    private const string SOURCE_URL = 'https://bakery-ops.orcamind.dev/demo-seed';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }
        $count = self::loadDevices();
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
                $this->demoDevicesIn($branch);
                $this->receiptSettingsOf($branch);
                if ($count > 0) {
                    $this->loadDevicesIn($branch, $count);
                }
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
        foreach (self::loadDeviceKinds($count) as $index => $kind) {
            $this->enrol($branch, sprintf('load-%02d', $index + 1), sprintf('Load test device %02d', $index + 1), $kind, null);
        }
    }

    /** The demo branch's pad, kitchen display, till and print bridge (DEMO_DEVICES). */
    private function demoDevicesIn(Branch $branch): void
    {
        foreach (self::DEMO_DEVICES as $fingerprint => [$name, $kind, $stationCode]) {
            $stationId = $stationCode === null ? null : Station::query()->where('code', $stationCode)->value('id');
            $this->enrol($branch, $fingerprint, $name, $kind, $stationId === null ? null : (int) $stationId);
        }
    }

    /**
     * Enrols a device as POST /devices does, unless one is already enrolled under the fingerprint. A bridge's token, returned once by
     * EnrolDevice, is dropped here: the demo has no shop computer to give it to.
     */
    private function enrol(Branch $branch, string $fingerprint, string $name, string $kind, ?int $stationId): void
    {
        if (Device::query()->withoutGlobalScopes()->where('fingerprint', $fingerprint)->exists()) {
            return;
        }
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_URL, self::SOURCE_URL."/{$branch->id}/device/{$fingerprint}")->toString();
        app(EnrolDevice::class)->handle($uuid, $name, $fingerprint, $kind, $stationId);
    }

    /** The receipts' sample TIN and 80 mm paper, saved like PUT /receipt-settings when the branch's settings have no TIN yet. */
    private function receiptSettingsOf(Branch $branch): void
    {
        $row = ReceiptSettings::query()->first();
        if ($row !== null && $row->tin !== null && trim($row->tin) !== '') {
            return;
        }
        $settings = ReceiptSettings::view($row, $branch->name);
        unset($settings['is_default']);
        $settings['tin'] = self::SAMPLE_TIN;
        $settings['paper_width_mm'] = self::PAPER_WIDTH_MM;
        app(SaveReceiptSettings::class)->handle($settings);
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
