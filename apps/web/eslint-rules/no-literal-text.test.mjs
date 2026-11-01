// node --test eslint-rules/: the literal-text rule of step 5.4a on a handful of JSX shapes (web-lint runs it before `pnpm lint`).
import { describe, it } from "node:test";
import { RuleTester } from "eslint";
import plugin from "./no-literal-text.mjs";

RuleTester.describe = describe;
RuleTester.it = it;
RuleTester.itOnly = it.only;

const tester = new RuleTester({
  languageOptions: { ecmaVersion: "latest", sourceType: "module", parserOptions: { ecmaFeatures: { jsx: true } } },
});

const literal = (count) => Array.from({ length: count }, () => ({ messageId: "literal" }));

tester.run("no-literal-text", plugin.rules["no-literal-text"], {
  valid: [
    "const a = <p>{t('title')}</p>;",
    "const a = <p>{qty} · {unit} — ({n}) × → 0 ⌫</p>;",
    "const a = <p>{`${qty} ${unit}`}</p>;",
    "const a = <p>{`#${id}`}</p>;",
    "const a = <div className=\"flex gap-2\" data-testid=\"pad-send\" role=\"status\" lang=\"am\" />;",
    "const a = <input type=\"text\" inputMode=\"numeric\" autoComplete=\"off\" />;",
    "const a = <p aria-label={t('close')} title={name}>{on ? t('yes') : t('no')}</p>;",
    "const label = 'Not in JSX'; const a = <p>{t(label)}</p>;",
    "const a = <p>{format(value, 'en')}</p>;",
  ],
  invalid: [
    { code: "const a = <p>Hello</p>;", errors: literal(1) },
    { code: "const a = <p>ሰላም</p>;", errors: literal(1) },
    { code: "const a = <p>{'World'}</p>;", errors: literal(1) },
    { code: "const a = <p>{on ? 'Yes' : `No ${n}`}</p>;", errors: literal(2) },
    { code: "const a = <p>{on && 'Maybe'}</p>;", errors: literal(1) },
    { code: "const a = <button aria-label=\"Close\" title={'Shut'} />;", errors: literal(2) },
    { code: "const a = <input placeholder={`Type ${n}`} />;", errors: literal(1) },
    { code: "const a = <img alt=\"Logo\" />;", errors: literal(1) },
  ],
});
