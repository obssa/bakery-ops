// k6 load test of the shop's busiest hour (phase-5 plan 6, step 5.5d; 10.2 D15). CI only: the perf-load job runs it against the compose
// stack on the runner (never against the live host, which is shared, CPU-limited and behind Cloudflare; PollBudgetTest states what the
// live host is asked for instead).
//
// Skeleton (first commit of the step): the thresholds of the plan. The devices and the burst follow.
export const options = {
  thresholds: {
    "http_req_duration{kind:poll}": ["p(95)<300"],
    "http_req_duration{kind:write}": ["p(95)<800"],
    http_req_failed: ["rate<0.005"],
  },
};

export default function () {}
