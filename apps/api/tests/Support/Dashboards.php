<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The branch the dashboard endpoint tests read (step 4.4c): one person of every role (the chef works in the kitchen, the barista at the
 * bar, the cashier at the till), the three stations, and SummaryRows to write the figures a test reads. Today is Sunday 2026-11-22,
 * 10:00 in Africa/Addis_Ababa (07:00Z); weeks start on Monday. get() reads GET /api/v1/analytics/{path} as one of the roles, with only
 * the headers given (none are carried over from an earlier request of the test).
 */
final class Dashboards
{
    public const string TODAY = '2026-11-22';

    public const array ROLES = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'];

    public Branch $branch;

    /** @var array<string, User> */
    public array $users = [];

    public Station $kitchen;

    public Station $bar;

    public Station $till;

    public SummaryRows $rows;

    private function __construct(private readonly TestCase $test) {}

    public static function open(TestCase $test): self
    {
        $d = new self($test);
        $test->travelTo(CarbonImmutable::parse('2026-11-22 07:00:00', 'UTC'));
        seedRoles();
        $d->branch = actInBranch(Branch::factory()->create());
        $names = ['admin' => 'Admin', 'manager' => 'Meron', 'cashier' => 'Selam', 'waiter' => 'Abebe', 'chef' => 'Chaltu', 'barista' => 'Dawit',
            'cake_baker' => 'Fikirte', 'bread_baker' => 'Girma'];
        foreach ($names as $role => $name) {
            $d->users[$role] = makeUser($d->branch, $role, ['name' => $name]);
        }
        withActor($d->users['admin']->id);
        $d->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $d->branch->id, 'name_en' => 'Kitchen', 'name_am' => 'ኩሽና']);
        $d->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $d->branch->id, 'name_en' => 'Bar', 'name_am' => 'ባር']);
        $d->till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $d->branch->id, 'name_en' => 'Till', 'name_am' => 'ካሻ']);
        foreach (['chef' => $d->kitchen, 'barista' => $d->bar, 'cashier' => $d->till] as $role => $station) {
            app(Stations::class)->replace($d->users[$role], [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => false]]);
        }
        $d->rows = new SummaryRows($d->branch->id);

        return $d;
    }

    public function channel(string $code): int
    {
        return (int) PaymentChannel::query()->where('code', $code)->value('id');
    }

    /** @param  array<string, mixed>  $attributes */
    public function product(string $nameEn, string $nameAm, array $attributes = []): Item
    {
        return Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'name_en' => $nameEn, 'name_am' => $nameAm] + $attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function ingredient(string $nameEn, string $nameAm, array $attributes = []): Item
    {
        return Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => $nameEn, 'name_am' => $nameAm] + $attributes);
    }

    public function category(string $nameEn, string $nameAm): Category
    {
        return Category::factory()->product()->create(['branch_id' => $this->branch->id, 'name_en' => $nameEn, 'name_am' => $nameAm]);
    }

    /**
     * @param  array<string, int|string>  $query
     * @param  array<string, string>  $headers
     */
    public function get(string $path, array $query = [], string $as = 'admin', array $headers = []): TestResponse
    {
        loginAs($this->users[$as]);
        $this->test->flushHeaders();

        return $this->test->withHeaders($headers)->getJson('/api/v1/analytics/'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * Every role, and whether it may read (a dataset: "<role>" => [role, allowed]).
     *
     * @param  list<string>  $allowed
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function roles(array $allowed): array
    {
        $cases = [];
        foreach (self::ROLES as $role) {
            $cases[$role] = [$role, in_array($role, $allowed, true)];
        }

        return $cases;
    }

    /**
     * The refusals of the common query (RangeRequest), today being 2026-11-22: [query, message_key, extra fields].
     *
     * @return array<string, array{0: array<string, string>, 1: string, 2: array<string, int|string>}>
     */
    public static function rangeErrors(): array
    {
        return [
            'over 400 days' => [['from' => '2025-10-17', 'to' => '2026-11-21'], 'analytics.range.too_large', ['max_days' => 400]],
            'tomorrow' => [['from' => '2026-11-20', 'to' => '2026-11-23'], 'analytics.range.future', ['today' => self::TODAY]],
            'from tomorrow' => [['from' => '2026-11-23'], 'analytics.range.future', ['today' => self::TODAY]],
            'backwards' => [['from' => '2026-11-21', 'to' => '2026-11-20'], 'validation.failed', []],
            'not a date' => [['from' => '2026-02-30'], 'validation.failed', []],
            'compare with what' => [['compare' => 'next_year'], 'validation.failed', []],
        ];
    }
}
