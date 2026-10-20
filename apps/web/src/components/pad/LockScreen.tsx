"use client";

import { useTranslations } from "next-intl";
import { useCallback, useEffect, useState } from "react";
import { useActing } from "@/lib/acting";
import { useErrorText } from "@/lib/errors";
import { Alert, Button } from "../ui";
import { focusRing } from "../styles";

// The pad when nobody has it: a numeric keypad takes the waiter short code (4 to 6 digits). A wrong code is refused
// with the API message in the page language; after five wrong codes the API answers 423 and the pad says so. Nothing
// of the last waiter is shown here, only why the pad locked.

const MAX_DIGITS = 6;
const MIN_DIGITS = 4;
const DIGITS = ["1", "2", "3", "4", "5", "6", "7", "8", "9"] as const;

const keyClass = `inline-flex min-h-16 items-center justify-center rounded-lg border border-neutral-300 bg-white text-2xl font-semibold text-neutral-900 hover:bg-neutral-100 active:bg-neutral-200 ${focusRing}`;

export function LockScreen() {
  const t = useTranslations("pad");
  const errorText = useErrorText();
  const unlock = useActing((s) => s.unlock);
  const lockedBy = useActing((s) => s.lockedBy);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const press = useCallback((digit: string) => setCode((current) => (current.length < MAX_DIGITS ? current + digit : current)), []);

  const submit = useCallback(async () => {
    if (busy || code.length < MIN_DIGITS) return;
    setBusy(true);
    setError(null);
    try {
      await unlock(code);
    } catch (failure) {
      setError(failure);
    } finally {
      // a code is never kept on screen after it was tried
      setCode("");
      setBusy(false);
    }
  }, [busy, code, unlock]);

  // a keyboard on the pad (or a test) types the same code
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.target instanceof HTMLElement && ["INPUT", "TEXTAREA", "SELECT"].includes(event.target.tagName)) return;
      if (/^\d$/.test(event.key)) press(event.key);
      else if (event.key === "Backspace") setCode((current) => current.slice(0, -1));
      else if (event.key === "Enter" && event.target instanceof HTMLElement && event.target.tagName !== "BUTTON") void submit();
    }
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [press, submit]);

  return (
    <section aria-labelledby="pad-lock-title" className="mx-auto w-full max-w-sm space-y-4">
      <h2 id="pad-lock-title" className="text-xl font-semibold text-neutral-900">
        {t("enter_code")}
      </h2>
      {lockedBy === "idle" || lockedBy === "expired" ? (
        <p role="status" className="rounded-md bg-neutral-100 px-4 py-3 text-base text-neutral-800">
          {lockedBy === "idle" ? t("lock_idle") : t("lock_expired")}
        </p>
      ) : null}
      <div className="flex min-h-16 items-center justify-center rounded-lg border border-neutral-300 bg-white" aria-live="polite">
        <output aria-label={t("code_label")} className="text-3xl font-semibold tracking-[0.5em] text-neutral-900">
          {code.length > 0 ? "●".repeat(code.length) : " "}
        </output>
      </div>
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <div className="grid grid-cols-3 gap-3">
        {DIGITS.map((digit) => (
          <button key={digit} type="button" className={keyClass} onClick={() => press(digit)} disabled={busy}>
            {digit}
          </button>
        ))}
        <button type="button" aria-label={t("clear_aria")} className={`${keyClass} text-lg`} onClick={() => setCode("")} disabled={busy}>
          {t("clear")}
        </button>
        <button type="button" className={keyClass} onClick={() => press("0")} disabled={busy}>
          0
        </button>
        <Button type="button" className="min-h-16 text-lg" onClick={() => void submit()} disabled={busy || code.length < MIN_DIGITS}>
          {busy ? t("checking") : t("unlock")}
        </Button>
      </div>
    </section>
  );
}
