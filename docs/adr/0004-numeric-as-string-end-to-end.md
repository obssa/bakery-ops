# ADR 0004: Money and quantities are NUMERIC in Postgres and decimal strings everywhere else

- Status: accepted
- Date: 2026-10-18
- Phase: 0

## Context

Recipes say 250 g, purchases say 50 kg sacks, prices are in birr and cents, counts are compared to the
ledger to four decimals. A float anywhere in that path produces `12.499999` variances that need a human
reason at every handover. PDO returns Postgres `NUMERIC` as strings; `json_encode` of PHP floats loses
precision; `parseFloat` in the browser does the same.

## Decision

- Columns: money `NUMERIC(12,2)`, quantities `NUMERIC(14,4)` in the item's base unit. No `float`,
  `double` or `real` anywhere.
- PHP: value objects `App\Shared\Quantity` and `App\Shared\Money` over `brick/math` `BigDecimal`;
  Eloquent casts to those objects; arithmetic only through them; rounding modes explicit
  (half-up to 4 dp for quantities, configurable cash rounding for ETB at the till).
- API: JSON emits money and quantities as strings (`"12.50"`, `"0.2500"`). The generated TypeScript
  client types them as `string`; UI formatting uses `Intl.NumberFormat` on a decimal string helper,
  never `parseFloat` on domain values.
- Tests compare decimal strings.

## Consequences

- Positive: ledger sums are exact; hash-chained count payloads are reproducible across PHP versions;
  variances are real variances.
- Negative: slightly more verbose code; developers must learn the two value objects.

## Enforcement

`scripts/check_invariants.py --numeric` (migration lint, `(float)` casts), `tests/Invariants/NumericColumnsTest.php`
(schema), ESLint rule banning `parseFloat`/`Number()` on fields matching money/qty names, PHPStan level 8.

## Alternatives considered

- Integers in minor units (cents, milligrams): exact, but recipes and units need four decimals and
  conversions, and every display needs a divisor. Rejected in favour of NUMERIC with explicit scale.

## Note, 2026-10-21: cost rates (invariants version 2)

A cost per gram (0.0850 ETB) cannot be held in two decimals, and the moving average of a purchase cost is a rate, not a
total. The rule therefore has two kinds of money column. A **total of money** (`total`, `line_total`, `paid_total`,
`cost_total`) stays `DECIMAL(12,2)`. A **cost rate**, a price per base unit, is any column with the word `cost` in its name
(`unit_cost`, `unit_cost_base`, `moving_avg_cost`, `cost_before`, `cost_after`; a name ending `_total` is a total) and is
`DECIMAL(14,4)`, held in PHP as `App\Shared\Values\UnitCost` (brick/math, half-up at the fourth decimal). A rate multiplied by
a `Quantity` gives `Money`, rounded half-up to two decimals once (`UnitCost::times`). The three checks changed together
(`scripts/check_invariants.py --numeric` and its selftest, `NumericColumnsTest`, the skill's copy of the script); the failing
case was written first against a planted `decimal('unit_cost', 12, 2)`. `invariants_version` in `STATUS.json` is now 2.
