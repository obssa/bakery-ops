"use client";

import type { UseQueryResult } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { useErrorText } from "@/lib/errors";
import { Alert, Button, Skeleton } from "../ui";

// A table card's body while its read is on its way or has failed (step 6.4d), so the card, its title and its filters stay in place and only
// the rows change: grey rows hidden from readers while a first read is pending (with one polite line that says so), the API's message
// and a retry in one row when it failed, the rows themselves once it answered. The kit's TableCard draws the card around them.

/** Grey rows standing in for a table's first read, across `span` columns. */
export function LoadingRows({ span, rows = 4, label }: { span: number; rows?: number; label?: string }) {
  const tc = useTranslations("common");
  return (
    <>
      {Array.from({ length: rows }, (_, i) => (
        <tr key={i} aria-hidden={i > 0 || undefined}>
          <td colSpan={span}>
            {i === 0 ? (
              <span role="status" className="sr-only">
                {label ?? tc("loading")}
              </span>
            ) : null}
            <Skeleton className="h-6" />
          </td>
        </tr>
      ))}
    </>
  );
}

/** A read that failed, across `span` columns: the API's message in the page's language and "Try again". */
export function ErrorRow({ span, error, onRetry }: { span: number; error: unknown; onRetry: () => void }) {
  const tc = useTranslations("common");
  const errorText = useErrorText();
  return (
    <tr>
      <td colSpan={span} className="py-4">
        <div className="space-y-3">
          <Alert>{errorText.message(error)}</Alert>
          <Button variant="secondary" size="md" onClick={onRetry}>
            {tc("retry")}
          </Button>
        </div>
      </td>
    </tr>
  );
}

/** A query's rows: loading rows, the error row, or `children` with its data. */
export function QueryRows<T>({ query, span, rows, children }: { query: UseQueryResult<T>; span: number; rows?: number; children: (data: T) => ReactNode }) {
  if (query.isPending) return <LoadingRows span={span} rows={rows} />;
  if (query.isError) return <ErrorRow span={span} error={query.error} onRetry={() => void query.refetch()} />;
  return <>{children(query.data)}</>;
}
