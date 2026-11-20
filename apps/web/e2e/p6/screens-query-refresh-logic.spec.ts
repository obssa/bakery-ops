import { expect, test } from "@playwright/test";
import { QueryClient, QueryObserver } from "@tanstack/react-query";
import { AppQueryClient, needsFreshLoad } from "../../src/lib/api/queryClient";

// A write's refresh while a screen's first load is still out (2026-11-20, the live flake of p2 purchase-admin: deploy 44787036661).
// TanStack Query hands an invalidation the in-flight first load's promise, which was sent before the write; the app's query client
// cancels that load first so the refetch is a fresh one. Plain logic, no browser and no API: it runs in p6-a11y beside the screens.

/** A list whose server answers in the order the test releases them: each call returns the server's state when it was sent. */
function slowServer() {
  let rows = ["old rule"];
  const pending: Array<() => void> = [];
  let calls = 0;
  return {
    write(row: string) {
      rows = [...rows, row];
    },
    get calls() {
      return calls;
    },
    fn: () => {
      calls += 1;
      const answer = [...rows];
      return new Promise<string[]>((resolve) => pending.push(() => resolve(answer)));
    },
    releaseAll() {
      while (pending.length > 0) pending.shift()?.();
    },
  };
}

async function settle() {
  for (let i = 0; i < 5; i += 1) await new Promise((resolve) => setTimeout(resolve, 0));
}

async function writeDuringFirstLoad(client: QueryClient) {
  const server = slowServer();
  const observer = new QueryObserver(client, { queryKey: ["inv", "reorder-list"], queryFn: server.fn, retry: false });
  const unsubscribe = observer.subscribe(() => {});
  await settle();
  expect(server.calls).toBe(1); // the screen's first load is out

  server.write("new rule"); // the write lands
  const refreshed = client.invalidateQueries({ queryKey: ["inv"] });
  server.releaseAll(); // the first load answers, with the list from before the write
  await settle();
  server.releaseAll(); // and a refetch, if one was sent
  await refreshed;
  await settle();
  const shown = observer.getCurrentResult();
  unsubscribe();
  return { calls: server.calls, data: shown.data, status: shown.status };
}

test.describe("a write's refresh during a first load", () => {
  test("the stock QueryClient keeps the pre-write answer (the bug this guards)", async () => {
    const result = await writeDuringFirstLoad(new QueryClient());
    expect(result.calls).toBe(1);
    expect(result.data).toEqual(["old rule"]);
  });

  test("the app's client cancels the first load and refetches: the list shows the write", async () => {
    const result = await writeDuringFirstLoad(new AppQueryClient());
    expect(result.calls).toBe(2);
    expect(result.status).toBe("success");
    expect(result.data).toEqual(["old rule", "new rule"]);
  });

  test("a query with data or with nobody showing it is left to TanStack's own refetch", () => {
    expect(needsFreshLoad({ fetchStatus: "fetching", data: undefined }, true)).toBe(true);
    expect(needsFreshLoad({ fetchStatus: "fetching", data: ["x"] }, true)).toBe(false); // cancelRefetch already covers it
    expect(needsFreshLoad({ fetchStatus: "fetching", data: undefined }, false)).toBe(false); // not refetched now: nothing to cancel
    expect(needsFreshLoad({ fetchStatus: "idle", data: undefined }, true)).toBe(false);
  });

  test("refetchType none cancels nothing", async () => {
    const client = new AppQueryClient();
    const server = slowServer();
    const observer = new QueryObserver(client, { queryKey: ["inv", "list"], queryFn: server.fn, retry: false });
    const unsubscribe = observer.subscribe(() => {});
    await settle();
    await client.invalidateQueries({ queryKey: ["inv"], refetchType: "none" });
    server.releaseAll();
    await settle();
    expect(server.calls).toBe(1);
    expect(observer.getCurrentResult().data).toEqual(["old rule"]);
    unsubscribe();
  });
});
