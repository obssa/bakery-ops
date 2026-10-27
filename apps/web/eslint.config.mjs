import { dirname } from "path";
import { fileURLToPath } from "url";
import { FlatCompat } from "@eslint/eslintrc";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const compat = new FlatCompat({
  baseDirectory: __dirname,
});

// ADR 0004: money and quantities are decimal strings end to end. parseFloat is never used, and Number() is never applied to a field or a
// variable whose name says it holds money, a quantity or a share of them: lib/decimal.ts compares and adds them as scaled integers, and a
// chart places them in pixels with decimalRatio (lib/charts.ts). Added with step 4.5e, whose charts are the first screens that need a
// figure's size; the ADR had named the rule since Phase 0.
const MONEY_OR_QTY = "/(amount|price|total|revenue|cost|margin|tender|balance|refund|paid|discount|tax|deposit|qty|quantity|share|pct|percent)/i";
const DECIMAL_RULE = "Money and quantities are decimal strings (ADR 0004): use lib/decimal.ts, or decimalRatio for a chart's pixels.";

const eslintConfig = [
  ...compat.extends("next/core-web-vitals", "next/typescript"),
  {
    files: ["src/**/*.{ts,tsx}", "e2e/**/*.ts"],
    rules: {
      "no-restricted-syntax": [
        "error",
        { selector: "CallExpression[callee.name='parseFloat']", message: DECIMAL_RULE },
        { selector: "CallExpression[callee.object.name='Number'][callee.property.name='parseFloat']", message: DECIMAL_RULE },
        { selector: `CallExpression[callee.name='Number'][arguments.0.property.name=${MONEY_OR_QTY}]`, message: DECIMAL_RULE },
        { selector: `CallExpression[callee.name='Number'][arguments.0.name=${MONEY_OR_QTY}]`, message: DECIMAL_RULE },
      ],
    },
  },
  {
    ignores: [
      "node_modules/**",
      ".next/**",
      "out/**",
      "public/**",
      "build/**",
      "next-env.d.ts",
    ],
  },
];

export default eslintConfig;
