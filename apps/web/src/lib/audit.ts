import type { AuditEntry, AuditJson } from "./api/types";

// The rules of the audit screen (step 4.5f, docs/phases/phase-4-plan.md sections 4.7 and 6 item 5): plain functions over plain data, so
// the screen and e2e/p4/dashboards-audit-logic.spec.ts read the same rules. The filters live in the page's address (a reload, a "History"
// link and a shared link land on the same list) and become the query of GET /audit-log and of its export; a malformed value is dropped,
// never sent (4.5c's pattern). The drawer shows a row's before and after as a diff: the fields that changed first, a nested object folded
// with its own diff inside, a list shown and compared whole, and "[redacted]" (what the API's Snapshot puts in place of a PIN, a reference
// number, a file key or a signed URL, on write and again on every read) as a marker, never as data.

// --- the filters ----------------------------------------------------------------------------------------------------------------------

export interface AuditFilters {
  /** local dates, YYYY-MM-DD, of the row's time; "" for no bound */
  from: string;
  to: string;
  /** a person's id as text: the API finds them as the account signed in or as the person acting at a shared pad */
  actorId: string;
  /** a literal prefix of the action ("payment.", or a whole action such as "payment.void"); "" for every action */
  action: string;
  /** the morph string the API wrote (a model's class, or a short name), matched exactly */
  subjectType: string;
  subjectId: string;
  stationId: string;
  shiftId: string;
}

export const NO_AUDIT_FILTERS: AuditFilters = { from: "", to: "", actorId: "", action: "", subjectType: "", subjectId: "", stationId: "", shiftId: "" };

/** The API's limits (ListAuditLogRequest): the action at most 100 characters, the subject type at most 191. */
export const ACTION_MAX = 100;
export const SUBJECT_TYPE_MAX = 191;

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const ID = /^[1-9]\d{0,9}$/;

function isDate(value: string): boolean {
  if (!DATE.test(value)) return false;
  const day = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(day.getTime()) && day.toISOString().slice(0, 10) === value;
}

function idOf(value: string): string {
  const trimmed = value.trim();
  return ID.test(trimmed) ? trimmed : "";
}

function textOf(value: string, max: number): string {
  const trimmed = value.trim();
  return trimmed.length <= max ? trimmed : "";
}

/** The filters the page's address holds; anything malformed is left out (the address may have been typed or kept from an old link). */
export function auditFiltersFromParams(params: { get(name: string): string | null }): AuditFilters {
  const raw = (name: string) => params.get(name) ?? "";
  return {
    from: isDate(raw("from").trim()) ? raw("from").trim() : "",
    to: isDate(raw("to").trim()) ? raw("to").trim() : "",
    actorId: idOf(raw("actor_id")),
    action: textOf(raw("action"), ACTION_MAX),
    subjectType: textOf(raw("subject_type"), SUBJECT_TYPE_MAX),
    subjectId: idOf(raw("subject_id")),
    stationId: idOf(raw("station_id")),
    shiftId: idOf(raw("shift_id")),
  };
}

/** The query of GET /audit-log, of its export and of the page's address: the filters that are set. A malformed value is never sent. */
export function auditFilterParams(filters: AuditFilters): Record<string, string> {
  const query: Record<string, string> = {};
  if (isDate(filters.from)) query.from = filters.from;
  if (isDate(filters.to)) query.to = filters.to;
  const put = (name: string, value: string) => {
    if (value !== "") query[name] = value;
  };
  put("actor_id", idOf(filters.actorId));
  put("action", textOf(filters.action, ACTION_MAX));
  put("subject_type", textOf(filters.subjectType, SUBJECT_TYPE_MAX));
  put("subject_id", idOf(filters.subjectId));
  put("station_id", idOf(filters.stationId));
  put("shift_id", idOf(filters.shiftId));
  return query;
}

export type AuditFilterIssue = "dates" | "subject_id" | "shift_id";

/** What would the API refuse (422 validation.failed)? Dates the wrong way round, or an id that is not a whole number above zero. */
export function auditFilterIssue(filters: AuditFilters): AuditFilterIssue | null {
  if (filters.from && filters.to && filters.from > filters.to) return "dates";
  if (filters.subjectId.trim() !== "" && idOf(filters.subjectId) === "") return "subject_id";
  if (filters.shiftId.trim() !== "" && idOf(filters.shiftId) === "") return "shift_id";
  return null;
}

/** The row the drawer shows (?id=), or null. */
export function entryIdOf(params: { get(name: string): string | null }): number | null {
  const id = params.get("id") ?? "";
  return ID.test(id) ? Number(id) : null;
}

/** The export's file name, as GET /audit-log/export names it in its Content-Disposition (which a cross-origin page cannot read). */
export function auditExportFileName(filters: AuditFilters, format: "csv" | "xlsx", today: string): string {
  return `audit-log-${isDate(filters.from) ? filters.from : "all"}-${isDate(filters.to) ? filters.to : today}.${format}`;
}

/** The refusal of a file too large (422 audit.export.too_large) with the numbers the API sends beside it; null for anything else. */
export function tooLargeOf(error: unknown): { maxRows: number; rows: number } | null {
  if (typeof error !== "object" || error === null) return null;
  const { key, body } = error as { key?: unknown; body?: unknown };
  if (key !== "audit.export.too_large" || typeof body !== "object" || body === null) return null;
  const { max_rows: maxRows, rows } = body as { max_rows?: unknown; rows?: unknown };
  return typeof maxRows === "number" && typeof rows === "number" ? { maxRows, rows } : null;
}

// --- actions --------------------------------------------------------------------------------------------------------------------------------

/**
 * The prefixes of the actions the API writes, by area, offered as a list (any other prefix or a whole action is typed as free text). Every
 * action is `<subject>.<what>`; the API matches the prefix literally, so "payment." finds payment.void but not payment_channel.update.
 */
export const ACTION_PREFIXES = [
  "order.",
  "order_line.",
  "payment.",
  "payment_channel.",
  "receipt.",
  "receipt_settings.",
  "shift.",
  "count.",
  "handover_note.",
  "shift_template.",
  "variance_reason.",
  "stock.",
  "purchase.",
  "transfer.",
  "supplier.",
  "location.",
  "reorder_rule.",
  "inventory.",
  "production_request.",
  "production_batch.",
  "item.",
  "category.",
  "modifier.",
  "recipe.",
  "unit.",
  "dining_table.",
  "auth.",
  "user.",
  "short_code.",
  "device.",
  "station.",
  "notification.",
  "file.",
  "analytics.",
  "audit.",
] as const;

export const OTHER = "other";

/** The list's choice for an action filter: the prefix when it is one of the list, "" for none, else "other" (typed as free text). */
export function actionChoice(action: string): string {
  if (action === "") return "";
  return (ACTION_PREFIXES as readonly string[]).includes(action) ? action : OTHER;
}

/** The action filter of a choice in the list and the free text beside it. */
export function actionOf(choice: string, text: string): string {
  return choice === OTHER ? text.trim() : choice;
}

// --- subjects -------------------------------------------------------------------------------------------------------------------------------

/**
 * The subjects of the audit log by the morph string the API writes: a model's class for an AuditedAction over a model (no morph map is
 * registered, so getMorphClass() is the class), a short name for an event recorded without one (recordEvent). The "History" links of
 * the payment, the order and the count use these, and the order timeline of step 4.5d will too.
 */
export const SUBJECT_TYPES = {
  payment: "App\\Modules\\Payments\\Models\\Payment",
  payment_channel: "App\\Modules\\Payments\\Models\\PaymentChannel",
  receipt_settings: "App\\Modules\\Payments\\Models\\ReceiptSettings",
  order: "App\\Modules\\Orders\\Models\\Order",
  order_line: "App\\Modules\\Orders\\Models\\OrderLine",
  dining_table: "App\\Modules\\Orders\\Models\\DiningTable",
  shift: "App\\Modules\\Shifts\\Models\\ShiftInstance",
  stock_count: "App\\Modules\\Shifts\\Models\\StockCount",
  shift_template: "App\\Modules\\Shifts\\Models\\ShiftTemplate",
  handover_note: "App\\Modules\\Shifts\\Models\\HandoverNote",
  variance_reason: "App\\Modules\\Shifts\\Models\\VarianceReason",
  stock_movement: "stock_movement",
  purchase: "App\\Modules\\Inventory\\Models\\Purchase",
  transfer: "App\\Modules\\Inventory\\Models\\Transfer",
  supplier: "App\\Modules\\Inventory\\Models\\Supplier",
  location: "App\\Modules\\Inventory\\Models\\Location",
  reorder_rule: "App\\Modules\\Inventory\\Models\\ReorderRule",
  production_request: "App\\Modules\\Production\\Models\\ProductionRequest",
  production_batch: "App\\Modules\\Production\\Models\\ProductionBatch",
  item: "App\\Modules\\Catalog\\Models\\Item",
  category: "App\\Modules\\Catalog\\Models\\Category",
  recipe: "App\\Modules\\Catalog\\Models\\Recipe",
  user: "App\\Modules\\Identity\\Models\\User",
  device: "App\\Modules\\Identity\\Models\\Device",
  station: "App\\Shared\\Models\\Station",
  notification: "App\\Modules\\Notifications\\Models\\Notification",
  notification_endpoint: "notification_endpoint",
  stored_file: "stored_file",
  analytics_day: "analytics_day",
  none: "none",
} as const;

export type SubjectKind = keyof typeof SUBJECT_TYPES;

/** Short names some events write for a subject that also has a class (receipt.link names its order "order"). */
const SUBJECT_ALIASES: Record<string, SubjectKind> = { order: "order", notification: "notification" };

/** The kinds offered in the subject list (an event "about nothing" is not a subject to look for). */
export const SUBJECT_CHOICES = (Object.keys(SUBJECT_TYPES) as SubjectKind[]).filter((kind) => kind !== "none");

/** Which known subject a morph string is, or null for one this screen has no name for (shown as the API wrote it). */
export function subjectKindOf(type: string | null): SubjectKind | null {
  if (!type) return null;
  const kind = (Object.keys(SUBJECT_TYPES) as SubjectKind[]).find((k) => SUBJECT_TYPES[k] === type);
  return kind ?? SUBJECT_ALIASES[type] ?? null;
}

/** The list's choice for a subject type filter: the kind when it is a known class or name, "" for none, else "other" (free text). */
export function subjectChoice(type: string): string {
  if (type === "") return "";
  const kind = (SUBJECT_CHOICES as string[]).find((k) => SUBJECT_TYPES[k as SubjectKind] === type);
  return kind ?? OTHER;
}

/** The subject type filter of a choice in the list and the free text beside it. */
export function subjectTypeOf(choice: string, text: string): string {
  if (choice === OTHER) return text.trim();
  return choice === "" ? "" : (SUBJECT_TYPES[choice as SubjectKind] ?? "");
}

/** The address of a subject's history: the audit log filtered to it ("History" beside a payment, an order, a count). */
export function historyQuery(kind: SubjectKind, id: number): Record<string, string> {
  return { subject_type: SUBJECT_TYPES[kind], subject_id: String(id) };
}

/** The last piece of a class name ("App\\Modules\\Somewhere\\Models\\Thing" reads "Thing"); a short name as it is. */
export function shortType(type: string): string {
  const pieces = type.split("\\");
  return pieces[pieces.length - 1] || type;
}

// --- people ----------------------------------------------------------------------------------------------------------------------------------

/** A field that names a person (approved_by_user_id, user_id, acting_user_id, actor_id): its id, so the drawer can say who it is. */
export function personFieldId(key: string, value: unknown): number | null {
  if (!(/(^|_)user_id$/.test(key) || key === "actor_id")) return null;
  return typeof value === "number" && Number.isInteger(value) && value > 0 ? value : null;
}

export interface PersonOption {
  id: number;
  name: string | null;
}

/**
 * Whom the actor filter offers: the people list (GET /users, the admin's: people.manage) and everyone the rows on screen name (a manager
 * reads the log without the list), each once, the list's name first; the chosen id stays offered even when nobody on screen has it.
 * Named people by name, then the unnamed by id.
 */
export function peopleOptions(people: readonly PersonOption[], rows: readonly AuditEntry[], selected: string): PersonOption[] {
  const named = new Map<number, string | null>();
  for (const person of people) named.set(person.id, person.name);
  for (const row of rows) {
    for (const person of [row.actor, row.acting_user]) {
      if (!person) continue;
      if (!named.has(person.id) || (named.get(person.id) === null && person.name)) named.set(person.id, person.name);
    }
  }
  const chosen = idOf(selected);
  if (chosen !== "" && !named.has(Number(chosen))) named.set(Number(chosen), null);
  return [...named.entries()]
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => {
      if (a.name !== null && b.name !== null) return a.name.localeCompare(b.name) || a.id - b.id;
      if (a.name !== null) return -1;
      if (b.name !== null) return 1;
      return a.id - b.id;
    });
}

// --- the before/after diff -------------------------------------------------------------------------------------------------------------------

/** What the API's Snapshot writes in place of a hidden value (and, inside a text, in place of a signed URL or a file key). */
export const REDACTED = "[redacted]";

/** The fields every model row carries: shown last, never highlighted (the API's summary leaves them out too). */
export const BOOKKEEPING: readonly string[] = ["id", "branch_id", "created_at", "updated_at"];

export type TextPart = { text: string } | { redacted: true };

export type Shown =
  /** the field is not on that side */
  | { kind: "missing" }
  | { kind: "null" }
  /** the whole value was hidden by the API */
  | { kind: "redacted" }
  | { kind: "text"; parts: TextPart[] }
  /** a number or a boolean, as JSON writes it */
  | { kind: "scalar"; text: string }
  /** a list, shown whole on one line */
  | { kind: "list"; parts: TextPart[] }
  /** an object, folded on screen, with its fields */
  | { kind: "object"; rows: ValueRow[] };

export interface ValueRow {
  key: string;
  value: Shown;
}

export type DiffStatus = "changed" | "added" | "removed" | "same";

export interface DiffRow {
  key: string;
  status: DiffStatus;
  /** id, branch_id, created_at, updated_at of the top level: listed last and never highlighted */
  bookkeeping: boolean;
  before: Shown;
  after: Shown;
  /** both sides are objects: the diff of their fields, folded on screen */
  fields?: DiffRow[];
}

const MISSING: Shown = { kind: "missing" };

function isRecord(value: unknown): value is Record<string, AuditJson> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function has(record: Record<string, AuditJson>, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(record, key);
}

/** The same JSON value: objects whatever their key order, lists item by item in order. */
function sameJson(a: AuditJson | undefined, b: AuditJson | undefined): boolean {
  if (a === b) return true;
  if (Array.isArray(a) || Array.isArray(b)) {
    if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
    return a.every((item, i) => sameJson(item, b[i]));
  }
  if (isRecord(a) && isRecord(b)) {
    const keys = Object.keys(a);
    return keys.length === Object.keys(b).length && keys.every((key) => has(b, key) && sameJson(a[key], b[key]));
  }
  return false;
}

/** A text with the redacted pieces apart, so the screen shows the marker where each one stood. */
export function textParts(text: string): TextPart[] {
  const parts: TextPart[] = [];
  text.split(REDACTED).forEach((piece, i) => {
    if (i > 0) parts.push({ redacted: true });
    if (piece !== "") parts.push({ text: piece });
  });
  return parts;
}

function merged(parts: TextPart[]): TextPart[] {
  const out: TextPart[] = [];
  for (const part of parts) {
    const last = out[out.length - 1];
    if ("text" in part && last && "text" in last) out[out.length - 1] = { text: last.text + part.text };
    else out.push(part);
  }
  return out;
}

/** A value on one line, as JSON reads, with a redacted value as the marker (no quotes around it). */
function inline(value: AuditJson): TextPart[] {
  if (value === REDACTED) return [{ redacted: true }];
  if (typeof value === "string") return textParts(JSON.stringify(value));
  if (value === null || typeof value !== "object") return [{ text: JSON.stringify(value) }];
  if (Array.isArray(value)) return [{ text: "[" }, ...value.flatMap((item, i) => [...(i > 0 ? [{ text: ", " }] : []), ...inline(item)]), { text: "]" }];
  const entries = Object.entries(value);
  return [{ text: "{" }, ...entries.flatMap(([key, item], i) => [{ text: `${i > 0 ? ", " : ""}${JSON.stringify(key)}: ` }, ...inline(item)]), { text: "}" }];
}

/** How one value shows. */
export function shownOf(value: AuditJson): Shown {
  if (value === null) return { kind: "null" };
  if (value === REDACTED) return { kind: "redacted" };
  if (typeof value === "string") return { kind: "text", parts: textParts(value) };
  if (typeof value === "number" || typeof value === "boolean") return { kind: "scalar", text: JSON.stringify(value) };
  if (Array.isArray(value)) return { kind: "list", parts: merged(inline(value)) };
  return { kind: "object", rows: valuesOf(value) };
}

/** One side alone (the context, an event's payload, a create): its fields in the order they were written. */
export function valuesOf(values: AuditJson | undefined): ValueRow[] {
  if (Array.isArray(values)) return values.map((value, i) => ({ key: String(i), value: shownOf(value) }));
  if (!isRecord(values)) return [];
  return Object.entries(values).map(([key, value]) => ({ key, value: shownOf(value) }));
}

function rank(row: DiffRow): number {
  if (row.bookkeeping) return 2;
  return row.status === "same" ? 1 : 0;
}

function byKey(a: DiffRow, b: DiffRow): number {
  return a.key < b.key ? -1 : a.key > b.key ? 1 : 0;
}

function diffRows(before: Record<string, AuditJson>, after: Record<string, AuditJson>, top: boolean): DiffRow[] {
  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])];
  const rows = keys.map((key): DiffRow => {
    const inBefore = has(before, key);
    const inAfter = has(after, key);
    const was = before[key];
    const is = after[key];
    // a field missing on one side counts as null there, as the API's summary counts it
    const status: DiffStatus = !inBefore ? (is === null ? "same" : "added") : !inAfter ? (was === null ? "same" : "removed") : sameJson(was, is) ? "same" : "changed";
    const row: DiffRow = { key, status, bookkeeping: top && BOOKKEEPING.includes(key), before: inBefore ? shownOf(was) : MISSING, after: inAfter ? shownOf(is) : MISSING };
    if (inBefore && inAfter && isRecord(was) && isRecord(is)) row.fields = diffRows(was, is, false);
    return row;
  });
  return rows.sort((a, b) => rank(a) - rank(b) || byKey(a, b));
}

/**
 * The drawer's before/after: every field of either side; the changed, added and removed ones first, then the unchanged, then the
 * bookkeeping fields, each group by name. Two objects are diffed inside (folded on screen); lists are compared and shown whole.
 */
export function diffOf(before: AuditJson | undefined, after: AuditJson | undefined): DiffRow[] {
  return diffRows(isRecord(before) ? before : {}, isRecord(after) ? after : {}, true);
}

/** Is a nested object open when the drawer opens? Only when something inside it changed. */
export function foldOpen(row: DiffRow): boolean {
  return row.status !== "same";
}
