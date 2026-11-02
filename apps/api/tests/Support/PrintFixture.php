<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Enums\DeviceKind;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The print tests of step 5.3a: a print bridge enrolled the way POST /devices does it (a random token, only its sha256 stored), an order
 * paid in full, and a job queued by a cashier, written directly so a test starts from the state it is about.
 */
final class PrintFixture
{
    /**
     * A bridge of $branch, last seen $seen (now by default; null: never).
     *
     * @return array{0: Device, 1: string} the device and its token
     */
    public static function bridge(Branch $branch, ?CarbonImmutable $seen = null, bool $neverSeen = false): array
    {
        $token = bin2hex(random_bytes(32));
        $device = Device::factory()->create([
            'branch_id' => $branch->id,
            'name' => 'Bridge '.Str::random(6),
            'kind' => DeviceKind::Bridge,
            'token_hash' => hash('sha256', $token),
        ]);
        DB::table('devices')->where('id', $device->id)->update(['last_seen_at' => $neverSeen ? null : ($seen ?? CarbonImmutable::now())]);

        return [$device->refresh(), $token];
    }

    /** @return array<string, string> the header a bridge authenticates with */
    public static function auth(string $token): array
    {
        return ['Authorization' => 'Bridge '.$token];
    }

    /** An order of $by's branch paid in full ($status sent and settled, or paid or closed). */
    public static function paidOrder(User $by, int $orderNo = 1, string $status = 'sent'): Order
    {
        $order = Order::factory()->sent($orderNo)->create([
            'branch_id' => $by->branch_id, 'created_by_user_id' => $by->id, 'total' => '150.00', 'paid_total' => '150.00', 'settled_at' => now(),
        ]);
        if ($status !== 'sent') {
            DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
        }

        return $order->refresh();
    }

    /**
     * A job as POST /orders/{order}/print writes it, then moved to the state a test needs.
     *
     * @param  array<string, mixed>  $state
     */
    public static function job(Order $order, User $by, array $state = []): int
    {
        $now = CarbonImmutable::now();

        return (int) DB::table('print_jobs')->insertGetId($state + [
            'branch_id' => $order->branch_id,
            'client_uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'kind' => 'receipt',
            'copies' => 1,
            'status' => 'queued',
            'attempts' => 0,
            'created_by_user_id' => $by->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'station_id' => null,
            'device_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return object{status: string, attempts: int, claimed_by_device_id: int|null, failure_key: string|null} */
    public static function row(int $jobId): object
    {
        return DB::table('print_jobs')->where('id', $jobId)->first() ?? throw new RuntimeException("no print job {$jobId}");
    }
}
