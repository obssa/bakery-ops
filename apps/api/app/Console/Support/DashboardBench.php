<?php

declare(strict_types=1);

namespace App\Console\Support;

use App\Modules\Analytics\Http\Requests\RangeRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The dashboards' timed in-process budget (phase-4 plan 10.2 R-G, the substitute for k6 on the shared host), used by
 * `php artisan analytics:bench` (any host, the live one included) and by DashboardBudgetGateTest (gate:p4, on the seeded history), so
 * the two measure the same calls: for each GET /api/v1/analytics/* dashboard read, `warmUps` requests and then `requests` sequential
 * timed ones, without If-None-Match (every answer is a 200 with its body), through the HTTP kernel of this process, as one person.
 * It counts the statements a read makes of its own (from the moment the endpoint resolves its RangeRequest; the auth, actor and permission
 * plumbing before it is every route's) and in all, and the 50th and 95th percentiles and the slowest time. The budget is
 * analytics.budget_p95_ms (400 ms, which leaves 600 ms of the browser's 1000 ms for the network) and analytics.budget_max_queries (8).
 *
 * It writes nothing. The person is signed in for this process only (set on the sanctum guard: no token, so no personal_access_tokens
 * row and no last_used_at), the reads are GETs, and the exports (each writes its audit row) are not
 * among them. The framework's own cache (spatie's permission cache, on the database store of the live host) is filled as by any request.
 * Resolved per run: it keeps its counters on the instance.
 */
final class DashboardBench
{
    /** The dashboard reads, as DashboardBudgetTest lists them: name => [path under /api/v1/analytics/, query]. */
    public const array READS = [
        'freshness' => ['freshness', []],
        'overview' => ['overview', ['compare' => 'previous']],
        'sales by day' => ['sales', ['compare' => 'previous']],
        'sales by week' => ['sales', ['group' => 'week', 'compare' => 'previous']],
        'hourly sales' => ['sales/hourly', ['compare' => 'previous']],
        'channels' => ['channels', ['compare' => 'previous']],
        'items' => ['items', ['compare' => 'previous', 'limit' => 200]],
        'item page' => ['items/{item}', ['compare' => 'previous']],
        'stations' => ['stations', ['compare' => 'previous']],
        'staff' => ['staff', ['compare' => 'previous']],
        'shifts' => ['shifts', ['compare' => 'previous']],
    ];

    private int $own = 0;

    private int $all = 0;

    private bool $inEndpoint = false;

    private bool $listening = false;

    public function __construct(private readonly HttpKernel $kernel) {}

    /**
     * Times the reads of $reads (READS by default) over [$from, $to] as $user. A read whose path names {item} is left out when $itemId
     * is null (a branch that sold nothing in the range has no product page to read).
     *
     * @param  array<string, array{0: string, 1: array<string, int|string>}>|null  $reads
     * @return list<array{read: string, path: string, status: int, own: int, all: int, p50: float, p95: float, max: float, bytes: int}>
     */
    public function run(User $user, string $from, string $to, ?int $itemId, int $requests = 30, int $warmUps = 3, ?array $reads = null): array
    {
        $this->listen();
        $results = [];
        foreach ($reads ?? self::READS as $name => [$path, $query]) {
            if (str_contains($path, '{item}')) {
                if ($itemId === null) {
                    continue;
                }
                $path = str_replace('{item}', (string) $itemId, $path);
            }
            $uri = '/api/v1/analytics/'.$path;
            $query += ['from' => $from, 'to' => $to];
            $status = 0;
            $bytes = 0;
            for ($n = 0; $n < $warmUps; $n++) {
                [$status, $bytes] = $this->request($user, $uri, $query);
            }
            [$status, $bytes] = $this->request($user, $uri, $query);
            $own = $this->own;
            $all = $this->all;
            $times = [];
            for ($n = 0; $n < $requests; $n++) {
                $started = hrtime(true);
                [$code] = $this->request($user, $uri, $query);
                $times[] = (hrtime(true) - $started) / 1e6;
                $status = $code === 200 ? $status : $code;
            }
            sort($times);
            $count = count($times);
            $results[] = [
                'read' => $name,
                'path' => $path,
                'status' => $status,
                'own' => $own,
                'all' => $all,
                'p50' => $count === 0 ? 0.0 : $times[(int) ceil(0.50 * $count) - 1],
                'p95' => $count === 0 ? 0.0 : $times[(int) ceil(0.95 * $count) - 1],
                'max' => $count === 0 ? 0.0 : $times[$count - 1],
                'bytes' => $bytes,
            ];
        }

        return $results;
    }

    /**
     * Whether a result keeps the budget: a 200, at most budget_max_queries statements of its own, a p95 under budget_p95_ms.
     *
     * @param  array{status: int, own: int, p95: float}  $result
     */
    public static function withinBudget(array $result): bool
    {
        return $result['status'] === 200
            && $result['own'] <= (int) config('analytics.budget_max_queries', 8)
            && $result['p95'] < (float) config('analytics.budget_p95_ms', 400);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array{0: int, 1: int} the status and the size of the body
     */
    private function request(User $user, string $uri, array $query): array
    {
        $this->inEndpoint = false;
        $this->own = 0;
        $this->all = 0;
        Auth::guard('sanctum')->setUser($user);
        Auth::shouldUse('sanctum');
        $request = Request::create($uri, 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return [$response->getStatusCode(), strlen((string) $response->getContent())];
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        DB::listen(function (QueryExecuted $query): void {
            $this->all++;
            $this->own += $this->inEndpoint ? 1 : 0;
        });
        app()->afterResolving(RangeRequest::class, function (): void {
            $this->inEndpoint = true;
        });
    }
}
