"use client";

import { focusRing } from "./styles";
import { useId, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from "react";

// The few building blocks every Phase 0 screen uses. Tablet-first (workflows-and-screens.md): every control is at
// least 48 px tall, focus is always visible, text stays legible at arm's length. Plain and quiet on purpose.

export function Button({
  variant = "primary",
  className = "",
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: "primary" | "secondary" }) {
  const look =
    variant === "primary"
      ? "bg-blue-700 text-white hover:bg-blue-800 disabled:bg-blue-700/60"
      : "border border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100 disabled:text-neutral-400";
  return (
    <button
      className={`inline-flex min-h-12 items-center justify-center rounded-md px-5 text-base font-medium transition-colors disabled:cursor-not-allowed ${look} ${focusRing} ${className}`}
      {...props}
    />
  );
}

const control =
  "block min-h-12 w-full rounded-md border border-neutral-300 bg-white px-3 text-base text-neutral-900 " +
  "placeholder:text-neutral-400 aria-[invalid=true]:border-red-600 " +
  focusRing;

interface FieldProps {
  label: string;
  hint?: string;
  error?: string;
  optionalLabel?: string;
  children: (props: { id: string; "aria-describedby"?: string; "aria-invalid"?: boolean }) => ReactNode;
}

/** A labelled control with an optional hint and error, wired for screen readers and for getByLabel. */
export function Field({ label, hint, error, optionalLabel, children }: FieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(" ") || undefined;
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="block text-sm font-medium text-neutral-800">
        {label}
        {optionalLabel ? <span className="font-normal text-neutral-500"> ({optionalLabel})</span> : null}
      </label>
      {children({ id, "aria-describedby": describedBy, "aria-invalid": error ? true : undefined })}
      {hint ? (
        <p id={hintId} className="text-sm text-neutral-600">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

export function TextInput({ className = "", ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={`${control} ${className}`} {...props} />;
}

export function Select({ className = "", ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={`${control} pr-8 ${className}`} {...props} />;
}

export function Alert({ children }: { children: ReactNode }) {
  return (
    <div role="alert" className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-base text-red-800">
      {children}
    </div>
  );
}

/** A polite live region; renders even when empty so screen readers pick up the first message. */
export function Status({ children }: { children?: ReactNode }) {
  return (
    <p role="status" aria-live="polite" className={children ? "rounded-md bg-green-50 px-4 py-3 text-base text-green-800" : "sr-only"}>
      {children}
    </p>
  );
}

/** A titled section: a landmark region named by its heading. */
export function Panel({ title, children, className = "" }: { title: string; children: ReactNode; className?: string }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`rounded-lg border border-neutral-200 bg-white p-5 ${className}`}>
      <h2 id={id} className="mb-4 text-lg font-semibold text-neutral-900">
        {title}
      </h2>
      {children}
    </section>
  );
}

export function PageHeader({ title, intro }: { title: string; intro?: string }) {
  return (
    <header className="mb-6 space-y-1">
      <h1 className="text-2xl font-semibold tracking-tight text-neutral-900">{title}</h1>
      {intro ? <p className="text-base text-neutral-600">{intro}</p> : null}
    </header>
  );
}

const BADGE_TONES = {
  neutral: "bg-neutral-100 text-neutral-800",
  blue: "bg-blue-100 text-blue-900",
  green: "bg-green-100 text-green-900",
  amber: "bg-amber-100 text-amber-900",
  red: "bg-red-100 text-red-900",
} as const;

/** A small status label. The words carry the meaning; the tone only adds to it. */
export function Badge({ tone = "neutral", children, className = "" }: { tone?: keyof typeof BADGE_TONES; children: ReactNode; className?: string }) {
  return <span className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${BADGE_TONES[tone]} ${className}`}>{children}</span>;
}
