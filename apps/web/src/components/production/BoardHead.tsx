"use client";

import type { ReactNode } from "react";
import { Chip } from "../ui";

// The production board's head (fresh-counter.md 3.5, the Production board's header): the board's name, its Amharic name beside it on the
// English page, a chip with the station and its shift, and the board's actions on the right. Kept as one small component with the props
// of the station top bar's header slot (title, titleAm, subtitle, actions) so the head moves into that slot in one edit once the shared
// station top bar carries one (step 6.3a); until then it is drawn at the top of the page. The title stays the page's h1.

export function BoardHead({ title, titleAm, subtitle, actions }: { title: string; titleAm?: string; subtitle?: string | null; actions?: ReactNode }) {
  return (
    <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
      <div className="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1.5">
        <h1 className="text-xl font-bold tracking-tight text-neutral-900">{title}</h1>
        {titleAm ? (
          <span lang="am" className="text-base font-medium text-neutral-600">
            {titleAm}
          </span>
        ) : null}
        {subtitle ? <Chip className="self-center">{subtitle}</Chip> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-3">{actions}</div> : null}
    </header>
  );
}
