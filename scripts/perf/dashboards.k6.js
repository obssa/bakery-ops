// k6 load test of the analytics dashboards (phase-4 plan 10.2 R-G; ci-cd.md, perf-dashboards). CI only: the perf-dashboards job runs it
// against the compose stack on the runner with the 90-day demo history (never against the live host, which is shared, behind Cloudflare
// and holds a small branch; `php artisan analytics:bench` measures there in process instead).
//
// Two admins read every dashboard of the 90 seeded days compared with the 90 before, page after page, with a second between pages: each
// read answers 200 with its figures, and the 95th percentile of each read and of all of them stays under 1000 ms (the browser's budget).
// The job signs in as the demo admin and passes:
//   BASE_URL  the API origin (http://localhost:8000)
//   TOKEN     the bearer token of the demo admin
//   FROM, TO  the 90 local days up to yesterday
//   ITEM      the product with the most revenue in them (its product page)
import http from "k6/http";
import { check, sleep } from "k6";

const base = `${__ENV.BASE_URL}/api/v1/analytics/`;
const range = `from=${__ENV.FROM}&to=${__ENV.TO}`;

// name => path and query, as DashboardBench::READS (apps/api/app/Console/Support/DashboardBench.php)
const READS = {
  freshness: `freshness?${range}`,
  overview: `overview?compare=previous&${range}`,
  sales_by_day: `sales?compare=previous&${range}`,
  sales_by_week: `sales?group=week&compare=previous&${range}`,
  hourly_sales: `sales/hourly?compare=previous&${range}`,
  channels: `channels?compare=previous&${range}`,
  items: `items?compare=previous&limit=200&${range}`,
  item_page: `items/${__ENV.ITEM}?compare=previous&${range}`,
  stations: `stations?compare=previous&${range}`,
  staff: `staff?compare=previous&${range}`,
  shifts: `shifts?compare=previous&${range}`,
};

const thresholds = {
  http_req_failed: ["rate==0"],
  checks: ["rate==1"],
  "http_req_duration{scenario:dashboards}": ["p(95)<1000"],
};
for (const name of Object.keys(READS)) thresholds[`http_req_duration{read:${name}}`] = ["p(95)<1000"];

export const options = {
  scenarios: {
    dashboards: { executor: "per-vu-iterations", vus: 2, iterations: 15, maxDuration: "10m" },
  },
  thresholds,
  summaryTrendStats: ["avg", "med", "p(90)", "p(95)", "max"],
};

const params = (name) => ({
  headers: { Authorization: `Bearer ${__ENV.TOKEN}`, Accept: "application/json" },
  tags: { read: name },
});

export default function () {
  for (const [name, path] of Object.entries(READS)) {
    const response = http.get(base + path, params(name));
    check(response, {
      [`${name}: 200`]: (r) => r.status === 200,
      [`${name}: a body`]: (r) => r.status === 200 && typeof r.body === "string" && r.body.length > 100,
    });
    sleep(1);
  }
}

// one line per read for the job log: p95 against the budget
export function handleSummary(data) {
  const lines = ["perf-dashboards (k6): p95 per read, budget 1000 ms"];
  for (const name of Object.keys(READS)) {
    const metric = data.metrics[`http_req_duration{read:${name}}`];
    const p95 = metric ? metric.values["p(95)"] : NaN;
    const med = metric ? metric.values.med : NaN;
    lines.push(`  ${name.padEnd(14)} p50 ${med.toFixed(1).padStart(7)} ms  p95 ${p95.toFixed(1).padStart(7)} ms`);
  }
  const all = data.metrics["http_req_duration{scenario:dashboards}"] || data.metrics.http_req_duration;
  lines.push(`  ${"all reads".padEnd(14)} p50 ${all.values.med.toFixed(1).padStart(7)} ms  p95 ${all.values["p(95)"].toFixed(1).padStart(7)} ms, ${data.metrics.http_reqs.values.count} requests`);
  return { stdout: `${lines.join("\n")}\n`, "/out/k6-summary.json": JSON.stringify(data, null, 2) };
}
