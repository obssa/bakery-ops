import { QueryClient, type InvalidateOptions, type InvalidateQueryFilters } from "@tanstack/react-query";

// The query cache every page shares (components/Providers.tsx), with one change to how a write's refresh works.
//
// invalidateQueries refetches the lists a write changed. But a query whose FIRST load is still in flight (no data yet) is not
// refetched: TanStack Query hands back that load's promise instead (Query.fetch reuses an in-flight fetch; cancelRefetch only
// applies once the query has data). That load was sent before the write, so the screen shows the list as it was before the
// write, until something else refreshes it. Seen on the live host 2026-11-20 (deploy 44787036661): the reorder screen's first
// GET /reorder-list was still out when the new rule was saved, so the list never showed the rule's item while the rules table did.
//
// So before invalidating, every active query the filters match whose first load is still in flight has that load cancelled
// (silently: no error, no state change). The refetch the invalidation then starts is a fresh one, sent after the write.

/** Whether an invalidation must cancel this query's in-flight load first: it is shown, it is loading and it has no data yet. */
export function needsFreshLoad(state: { fetchStatus: string; data: unknown }, active: boolean): boolean {
  return active && state.fetchStatus !== "idle" && state.data === undefined;
}

export class AppQueryClient extends QueryClient {
  override invalidateQueries(filters?: InvalidateQueryFilters, options?: InvalidateOptions): Promise<void> {
    if ((filters?.refetchType ?? "active") !== "none") {
      for (const query of this.getQueryCache().findAll(filters)) {
        if (needsFreshLoad(query.state, query.isActive())) void query.cancel({ silent: true });
      }
    }
    return super.invalidateQueries(filters, options);
  }
}
