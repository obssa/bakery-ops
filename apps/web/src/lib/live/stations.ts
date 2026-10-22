import type { CaseRow, DisplayCasePage, ProductionRequest, StockStripPage, StripRow } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { DEFAULT_POLL, pollTransport } from "./transport";

// The two small read views of the station screens (Phase 2 plan section 4, step 2.6b), each a whole answer behind an
// ETag, so a look is one conditional GET and an idle screen is a 304: the kitchen display's stock strip
// (GET /stations/{station}/stock-strip) and the till's display-case panel (GET /display-case). Both poll at the cadence
// of the kitchen display (about 2 s while the tab is visible, slower hidden): there is no cursor, the answer replaces
// the state.

export interface StripState {
  locationId: number | null;
  rows: StripRow[];
}

export const STRIP_ROWS = 8;

export const stripSpec: FeedSpec<StockStripPage, StripState> = {
  initial: { locationId: null, rows: [] },
  cursorOf: () => null,
  merge: (_state, page) => ({ locationId: page.location_id, rows: page.data }),
};

export function createStripFeed(stationId: number): LiveFeed<StockStripPage, StripState> {
  return new LiveFeed(feedSource<StockStripPage>(`/stations/${stationId}/stock-strip`, { query: { limit: STRIP_ROWS }, cursor: false }), stripSpec, pollTransport(DEFAULT_POLL));
}

export interface CaseState {
  locationId: number | null;
  rows: CaseRow[];
}

export const caseSpec: FeedSpec<DisplayCasePage, CaseState> = {
  initial: { locationId: null, rows: [] },
  cursorOf: () => null,
  merge: (_state, page) => ({ locationId: page.location_id, rows: page.data }),
};

export function createCaseFeed(): LiveFeed<DisplayCasePage, CaseState> {
  return new LiveFeed(feedSource<DisplayCasePage>("/display-case", { cursor: false }), caseSpec, pollTransport(DEFAULT_POLL));
}

/** A request that still has work to do: the product is being asked for, so the till shows "Requested" instead of the button. */
const OPEN = new Set(["requested", "accepted", "in_progress", "pending_confirmation"]);

export function openProductIds(requests: ProductionRequest[]): Set<number> {
  return new Set(requests.filter((request) => OPEN.has(request.status)).map((request) => request.product.id));
}
