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
