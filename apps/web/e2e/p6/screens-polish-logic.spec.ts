import { expect, test } from "@playwright/test";
import type { ConditionalResult } from "../../src/lib/api/client";
import type { AppNotification, NotificationsPage } from "../../src/lib/api/types";
import { LiveFeed } from "../../src/lib/live/feed";
import { inboxSpec, markedAllRead } from "../../src/lib/live/notifications";
import type { OutboxItem } from "../../src/lib/outbox/logic";
import { trayEntries } from "../../src/lib/outbox/tray";
import { CHUNK_RELOAD_GAP_MS, isChunkLoadError, mayAutoReload, mayLoadUpdate, mayReloadStale } from "../../src/lib/swUpdate";
import { syncCounts } from "../../src/lib/syncState";

// The implicit state machines review (2026-11-20), the low items: the sync chip's counts (item 24: lib/syncState.ts), a feed's answer
// that predates a change the screen made itself (item 27: lib/live/feed.ts amend), and a new version of the app (item 30:
// lib/swUpdate.ts). Plain logic, no browser and no API: it runs in p6-a11y beside the screens, as the other *-logic specs do.

let seq = 0;
function item(overrides: Partial<OutboxItem> = {}): OutboxItem {
  seq += 1;
  return {
    id: `i${seq}`,
    seq,
    kind: "send_order",
    method: "POST",
    path: "/orders",
    body: {},
    idempotencyKey: `key-${seq}`,
    subjectId: null,
    subjectUuid: null,
    group: null,
    dependsOn: null,
    needs: null,
    inline: false,
    userId: 7,
    screen: "pad",
    createdAt: 1_000,
    attempts: 0,
    nextAttemptAt: 0,
    state: "pending",
    conflict: null,
    ...overrides,
  };
}

const refused = (overrides: Partial<OutboxItem>, blockedBy: string | null = null) =>
  item({ ...overrides, state: "conflict", conflict: { status: 409, body: { message_key: "orders.shortfall" }, at: 2_000, blockedBy } });

test.describe("item 24: the sync chip counts what the person can act on", () => {
  test("the person's own writes are waiting; another account's are counted apart, never as waiting to send", () => {
    const queue = [item({ screen: "pad" }), item({ screen: "till" }), item({ userId: 9, screen: "pad" }), item({ userId: 9, screen: "till" })];
    expect(syncCounts(queue, null, 7, "pad")).toEqual({ here: 1, elsewhere: 1, others: 2, conflicts: 0 });
    // the other account signs in: its writes are its own now
    expect(syncCounts(queue, null, 9, "pad")).toEqual({ here: 1, elsewhere: 1, others: 2, conflicts: 0 });
    // nobody signed in: nothing is sent, every write waits
    expect(syncCounts(queue, null, null, "pad")).toEqual({ here: 2, elsewhere: 2, others: 0, conflicts: 0 });
    // a write queued before the session was known (no account) is the signed-in person's
    expect(syncCounts([item({ userId: 0 })], null, 7, "pad")).toMatchObject({ here: 1, others: 0 });
  });

  test("a write on the wire for the first time is not waiting yet; after a failed attempt it is", () => {
    const first = item();
    const retried = item({ attempts: 1 });
    expect(syncCounts([first, retried], first.id, 7, "pad").here).toBe(1);
    expect(syncCounts([first, retried], retried.id, 7, "pad").here).toBe(2);
  });

  test("the conflict count is the tray's: a write held back by another refused one is inside that one's entry", () => {
    const send = refused({ subjectUuid: "o-1" });
    const after = refused({ subjectUuid: "o-1" }, send.id);
    const other = refused({ subjectUuid: "o-2" });
    const queue = [send, after, other, item()];
    expect(trayEntries(queue)).toHaveLength(2);
    expect(syncCounts(queue, null, 7, "pad")).toEqual({ here: 1, elsewhere: 0, others: 0, conflicts: 2 });
  });
});

function line(id: number, read: string | null = null): AppNotification {
  return {
    id,
    type: "production_requested",
    severity: "info",
    title: "t",
    body: "b",
    title_en: "t",
    title_am: "t",
    body_en: "b",
    body_am: "b",
    payload: null,
    deep_link: null,
    read_at: read,
    created_at: "2026-11-20T10:00:00Z",
  } as AppNotification;
}

const page = (lines: AppNotification[], unread: number): NotificationsPage => ({ data: lines, unread_count: unread, cursor: "c", server_time: "2026-11-20T10:00:00Z" });

test.describe("item 27: a look asked before the screen's own change does not undo it", () => {
  test("the bell is read while a look is on its way: that answer is dropped, the next look says what the server says now", async () => {
    let release: (answer: ConditionalResult<NotificationsPage>) => void = () => {};
    const answers: Promise<ConditionalResult<NotificationsPage>>[] = [
      Promise.resolve({ notModified: false, etag: '"1"', data: page([line(1), line(2)], 2) }),
      new Promise((resolve) => {
        release = resolve;
      }),
      Promise.resolve({ notModified: false, etag: '"3"', data: page([line(1, "2026-11-20T10:05:00Z"), line(2, "2026-11-20T10:05:00Z")], 0) }),
    ];
    const etags: (string | null)[] = [];
    const feed = new LiveFeed<NotificationsPage, typeof inboxSpec.initial>(
      {
        fetchPage({ etag }) {
          etags.push(etag);
          return answers.shift()!;
        },
      },
      inboxSpec,
      { start: () => ({ stop() {}, kick() {} }) },
    );
    feed.start();
    expect(await feed.poll()).toBe("changed");
    expect(feed.getSnapshot().state.unread).toBe(2);

    // a poll leaves, then the person reads everything (the write was answered): the bell shows 0 at once
    const look = feed.poll();
    feed.amend((state) => markedAllRead(state, "2026-11-20T10:05:00Z"));
    expect(feed.getSnapshot().state.unread).toBe(0);

    // the poll's answer was asked before the read: it still counts 2, and must not bring the dot back
    release({ notModified: false, etag: '"2"', data: page([line(1), line(2)], 2) });
    expect(await look).toBe("unchanged");
    expect(feed.getSnapshot().state.unread).toBe(0);

    // the feed looked once more, from where the dropped look had asked (its ETag was not kept), and now the server agrees
    await expect.poll(() => etags.length).toBe(3);
    await expect.poll(() => feed.getSnapshot().revision).toBe(2);
    expect(etags).toEqual([null, '"1"', '"1"']);
    expect(feed.getSnapshot().state.unread).toBe(0);
    feed.stop();
  });

  test("a look with no change of the screen meanwhile is merged as before", async () => {
    const feed = new LiveFeed<NotificationsPage, typeof inboxSpec.initial>(
      { fetchPage: async () => ({ notModified: false, etag: '"1"', data: page([line(1)], 1) }) },
      inboxSpec,
      { start: () => ({ stop() {}, kick() {} }) },
    );
    expect(await feed.poll()).toBe("changed");
    expect(feed.getSnapshot().state.unread).toBe(1);
  });
});

test.describe("item 30: a new version of the app", () => {
  test("a part of the page that cannot be fetched is recognised, other errors are not", () => {
    const chunk = new Error("Loading chunk 812 failed.\n(error: https://example.test/app/_next/static/chunks/812.js)");
    chunk.name = "ChunkLoadError";
    expect(isChunkLoadError(chunk)).toBe(true);
    expect(isChunkLoadError(new TypeError("Failed to fetch dynamically imported module: https://example.test/x.js"))).toBe(true);
    expect(isChunkLoadError(new TypeError("Importing a module script failed."))).toBe(true);
    expect(isChunkLoadError("Loading CSS chunk 4 failed")).toBe(true);
    expect(isChunkLoadError(new TypeError("Failed to fetch"))).toBe(false);
    expect(isChunkLoadError(new Error("orders.shortfall"))).toBe(false);
    expect(isChunkLoadError(null)).toBe(false);
  });

  test("Reload to the new version only once nothing waits on the tablet", () => {
    expect(mayLoadUpdate({ queued: 0, durable: true })).toBe(true);
    expect(mayLoadUpdate({ queued: 1, durable: true })).toBe(false);
    expect(mayLoadUpdate({ queued: 1, durable: false })).toBe(false);
  });

  test("a missing part reloads the page by itself only when nothing waits, online, and not again within a minute", () => {
    const now = 10_000_000;
    const empty = { queued: 0, durable: true };
    expect(mayAutoReload({ waiting: empty, online: true, lastReloadAt: null, now })).toBe(true);
    expect(mayAutoReload({ waiting: empty, online: true, lastReloadAt: now - CHUNK_RELOAD_GAP_MS, now })).toBe(true);
    expect(mayAutoReload({ waiting: empty, online: true, lastReloadAt: now - 5_000, now })).toBe(false);
    expect(mayAutoReload({ waiting: empty, online: false, lastReloadAt: null, now })).toBe(false);
    expect(mayAutoReload({ waiting: { queued: 2, durable: true }, online: true, lastReloadAt: null, now })).toBe(false);
  });

  test("asked to reload, the person may when what waits is saved on the tablet, not when it lives in this page only", () => {
    expect(mayReloadStale({ queued: 0, durable: false })).toBe(true);
    expect(mayReloadStale({ queued: 3, durable: true })).toBe(true);
    expect(mayReloadStale({ queued: 3, durable: false })).toBe(false);
  });
});
