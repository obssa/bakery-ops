import type { ConditionalResult } from "../api/client";

// The one client for every cursor feed of ADR 0009 (tickets?since, orders?since, menu). A feed owns what the screen
// shows (a state built by merging pages), the cursor and the ETag of the last answer; a transport decides WHEN to look
// again (polling timers, or a WebSocket event). The poll path is the source of truth: whatever the transport, a look
// is always one conditional GET, and a missed message costs one fetch, never a lost ticket.

/** How a look ended: "changed" (a page was merged), "unchanged" (304), "failed" (error, back off) or "stopped". */
export type PollResult = "changed" | "unchanged" | "failed" | "stopped";

export interface FeedSource<Page> {
  /** One conditional GET of the feed: first load without a cursor, then with it. A 304 is `notModified`. */
  fetchPage(args: { cursor: string | null; etag: string | null; signal: AbortSignal }): Promise<ConditionalResult<Page>>;
}

export interface FeedSpec<Page, State> {
  initial: State;
  /** The cursor to send next, from a page (null when the page has none: the feed is then always read whole). */
  cursorOf(page: Page): string | null;
  /** Folds a page into the state. Pure: rows merge by id and version, the higher version wins. */
  merge(state: State, page: Page): State;
}

export interface FeedSnapshot<State> {
  state: State;
  cursor: string | null;
  /** true once the first page has been merged */
  loaded: boolean;
  /** the last look failed (network, server): the screen says it may be out of date */
  error: unknown | null;
  failures: number;
  /** grows with every merged page, for screens that react to "something new arrived" */
  revision: number;
}

export interface TransportController {
  poll(): Promise<PollResult>;
}

export interface TransportHandle {
  stop(): void;
  /** Look now (a focus, a reconnect, the screen's own write, a pushed event). */
  kick(): void;
}

export interface LiveTransport {
  start(controller: TransportController): TransportHandle;
}

export class LiveFeed<Page, State> {
  private snapshot: FeedSnapshot<State>;
  private listeners = new Set<() => void>();
  private handle: TransportHandle | null = null;
  private inFlight: Promise<PollResult> | null = null;
  private abort: AbortController | null = null;
  private etag: string | null = null;
  private running = false;
  private again = false;

  constructor(
    private readonly source: FeedSource<Page>,
    private readonly spec: FeedSpec<Page, State>,
    private readonly transport: LiveTransport,
  ) {
    this.snapshot = { state: spec.initial, cursor: null, loaded: false, error: null, failures: 0, revision: 0 };
  }

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  getSnapshot = (): FeedSnapshot<State> => this.snapshot;

  start(): void {
    if (this.running) return;
    this.running = true;
    this.handle = this.transport.start({ poll: () => this.poll() });
  }

  stop(): void {
    this.running = false;
    this.handle?.stop();
    this.handle = null;
    this.abort?.abort();
  }

  /** Look again now. Safe to call from anywhere: a look already in flight is never doubled, only repeated after. */
  refresh(): void {
    if (this.handle) this.handle.kick();
    else void this.poll();
  }

  /** One look. Never two requests in flight for one feed: a call during a look waits for it and looks once more. */
  poll(): Promise<PollResult> {
    if (this.inFlight) {
      this.again = true;
      return this.inFlight;
    }
    const run = this.look().finally(() => {
      this.inFlight = null;
      const repeat = this.again && this.running;
      this.again = false;
      if (repeat) void this.poll();
    });
    this.inFlight = run;
    return run;
  }

  private async look(): Promise<PollResult> {
    this.abort = new AbortController();
    const { signal } = this.abort;
    try {
      const result = await this.source.fetchPage({ cursor: this.snapshot.cursor, etag: this.etag, signal });
      if (signal.aborted) return "stopped";
      if (result.etag) this.etag = result.etag;
      if (result.notModified) {
        if (this.snapshot.error !== null || this.snapshot.failures > 0) this.set({ error: null, failures: 0 });
        return "unchanged";
      }
      const page = result.data;
      this.set({
        state: this.spec.merge(this.snapshot.state, page),
        cursor: this.spec.cursorOf(page) ?? this.snapshot.cursor,
        loaded: true,
        error: null,
        failures: 0,
        revision: this.snapshot.revision + 1,
      });
      return "changed";
    } catch (error) {
      if (signal.aborted) return "stopped";
      this.set({ error, failures: this.snapshot.failures + 1 });
      return "failed";
    }
  }

  private set(patch: Partial<FeedSnapshot<State>>): void {
    this.snapshot = { ...this.snapshot, ...patch };
    this.listeners.forEach((listener) => listener());
  }
}
