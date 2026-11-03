"use client";

import type { ReactNode } from "react";

// The head of a station screen drawn by the screen itself (step 6.3c: the production board, fresh-counter.md 3.5, and the count screen):
// the screen's name (its Amharic name beside it on the English page), a chip with the station and its shift, and the screen's actions on
// the right. It has the props of the station top bar's header slot (title, titleAm, subtitle, actions), so each screen moves its head into
// that slot in one edit once the shared station top bar carries one (step 6.3a), and this component goes. The title is the page's h1.

export function ScreenHead({
  title,
  titleAm,
  subtitle,
  status,
  actions,
}: {
  title: string;
  /** the name in Amharic, beside the English one on the English page */
  titleAm?: string;
  /** the station and its shift, in a neutral chip */
  subtitle?: string | null;
  /** a status chip after the subtitle (the count screen's shift state) */
  status?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
      <div className="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1.5">
        <h1 className="text-xl font-bold tracking-tight text-neutral-900">{title}</h1>
        {titleAm ? (
          <span lang="am" className="text-base font-medium text-neutral-600">
            {titleAm}
          </span>
        ) : null}
        {/* the board's neutral chip, white with a hairline here because the head sits on the ground, not on a white bar */}
        {subtitle ? (
          <span className="inline-flex min-h-7 items-center self-center rounded-full border border-neutral-200 bg-white px-3 text-[13px] font-semibold text-neutral-700">{subtitle}</span>
        ) : null}
        {status ? <span className="self-center">{status}</span> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-3">{actions}</div> : null}
    </header>
  );
}
