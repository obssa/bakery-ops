import { expect, test } from "@playwright/test";
import { foldHead, olderCursor, visibleRows, type ListPages } from "../../src/lib/adminPayments";
import {
  ACTION_PREFIXES,
  actionChoice,
  actionOf,
  auditExportFileName,
  auditFilterIssue,
  auditFilterParams,
  auditFiltersFromParams,
  diffOf,
  entryIdOf,
  foldOpen,
  historyQuery,
  NO_AUDIT_FILTERS,
  peopleOptions,
  personFieldId,
  REDACTED,
  shownOf,
  subjectKindOf,
  SUBJECT_TYPES,
  textParts,
  tooLargeOf,
  valuesOf,
  type DiffRow,
} from "../../src/lib/audit";
import type { AuditEntry } from "../../src/lib/api/types";
import { resolveDeepLink } from "../../src/lib/deeplink";

// Phase 4 gate project p4-dashboards (the name matches /dashboards/), step 4.5f: the audit screen's rules as plain logic (no browser, no
// API). The filters kept in the page's address and the query of GET /audit-log they become (4.5c's pattern: a malformed value is dropped,
// never sent), the action prefixes offered with free text beside them, the subjects' morph strings the "History" links use, the drawer's
// before/after diff (changed, added and removed fields, the redacted marker in place of a value, nested objects folded, lists shown whole,
// the bookkeeping fields never highlighted), the people the actor filter offers, the export's file name and its refusal, and the first
// page with the older pages (4.5c's foldHead and olderCursor, now over any row with an id).

const params = (query: Record<string, string>) => new URLSearchParams(query);
const PAYMENT = "App\\Modules\\Payments\\Models\\Payment";

function entry(id: number, actor: { id: number; name: string | null }, acting: { id: number; name: string | null } | null = null): AuditEntry {
  return {
    id,
    at: "2026-10-27T11:05:00+00:00",
    actor,
    acting_user: acting,
    action: "payment.void",
    subject_type: PAYMENT,
    subject_id: 41,
    station_id: 3,
    shift_id: 7,
    device_id: null,
    ip: null,
    summary: { changed: ["status"], more: 0, reason: "wrong transfer" },
  };
}

/** The rows of a diff as [key, status] pairs, nested fields under their parent's key. */
function shape(rows: DiffRow[]): unknown[] {
  return rows.map((row) => (row.fields ? [row.key, row.status, shape(row.fields)] : [row.key, row.status]));
}

test.describe("dashboards-audit-logic", () => {
  test("the filters live in the page's address and become the query of GET /audit-log; anything malformed is dropped", () => {
    expect(auditFiltersFromParams(params({}))).toEqual(NO_AUDIT_FILTERS);
    const all = {
      from: "2026-10-20",
      to: "2026-10-27",
      actor_id: "12",
      action: "payment.",
      subject_type: PAYMENT,
      subject_id: "41",
      station_id: "3",
      shift_id: "7",
    };
    const filters = auditFiltersFromParams(params(all));
    expect(filters).toEqual({ from: "2026-10-20", to: "2026-10-27", actorId: "12", action: "payment.", subjectType: PAYMENT, subjectId: "41", stationId: "3", shiftId: "7" });
    expect(auditFilterParams(filters)).toEqual(all);
    // the drawer's ?id= is not a filter, and a malformed value never reaches the API
    expect(
      auditFiltersFromParams(params({ id: "9", from: "2026-02-30", to: "yesterday", actor_id: "0", subject_id: "4.5", station_id: "-3", shift_id: "x", action: "  ", subject_type: "a".repeat(192) })),
    ).toEqual(NO_AUDIT_FILTERS);
    expect(auditFiltersFromParams(params({ action: "a".repeat(101) })).action).toBe("");
    expect(auditFiltersFromParams(params({ action: " order. ", subject_type: " order " }))).toMatchObject({ action: "order.", subjectType: "order" });
    expect(auditFilterParams({ ...NO_AUDIT_FILTERS, subjectId: "abc", shiftId: "12", from: "2026-10-01" })).toEqual({ from: "2026-10-01", shift_id: "12" });
    expect(auditFilterParams(NO_AUDIT_FILTERS)).toEqual({});
    // what the API would refuse, said before anything is sent
    expect(auditFilterIssue({ ...NO_AUDIT_FILTERS, from: "2026-10-27", to: "2026-10-20" })).toBe("dates");
    expect(auditFilterIssue({ ...NO_AUDIT_FILTERS, subjectId: "4.5" })).toBe("subject_id");
    expect(auditFilterIssue({ ...NO_AUDIT_FILTERS, shiftId: "seven" })).toBe("shift_id");
    expect(auditFilterIssue({ ...NO_AUDIT_FILTERS, from: "2026-10-20", to: "2026-10-20", subjectId: " 41 " })).toBeNull();
    // the drawer's row
    expect(entryIdOf(params({ id: "9" }))).toBe(9);
    expect(entryIdOf(params({ id: "09" }))).toBeNull();
    expect(entryIdOf(params({}))).toBeNull();
    // the address of a row is a page of the web (a link may carry it)
    expect(resolveDeepLink("/admin/audit?id=9&subject_id=41")).toEqual({ pathname: "/admin/audit", query: { id: "9", subject_id: "41" } });
  });

  test("the action is a known prefix picked from a list, or free text; the API matches it as a literal prefix", () => {
    for (const prefix of ["payment.", "order.", "order_line.", "count.", "shift.", "auth.", "audit."]) expect(ACTION_PREFIXES).toContain(prefix);
    expect(ACTION_PREFIXES.every((prefix) => /^[a-z_]+\.$/.test(prefix))).toBe(true);
    expect(new Set(ACTION_PREFIXES).size).toBe(ACTION_PREFIXES.length);
    expect(actionChoice("")).toBe("");
    expect(actionChoice("payment.")).toBe("payment.");
    expect(actionChoice("payment.void")).toBe("other");
    expect(actionChoice("pay")).toBe("other");
    expect(actionOf("payment.", "ignored")).toBe("payment.");
    expect(actionOf("other", " payment.void ")).toBe("payment.void");
    expect(actionOf("", "ignored")).toBe("");
  });

  test("a subject's history is the list filtered to its morph string, as the API writes it; known subjects are named", () => {
    expect(historyQuery("payment", 41)).toEqual({ subject_type: PAYMENT, subject_id: "41" });
    expect(historyQuery("order", 17)).toEqual({ subject_type: "App\\Modules\\Orders\\Models\\Order", subject_id: "17" });
    expect(historyQuery("stock_count", 5)).toEqual({ subject_type: "App\\Modules\\Shifts\\Models\\StockCount", subject_id: "5" });
    expect(subjectKindOf(PAYMENT)).toBe("payment");
    expect(subjectKindOf("order")).toBe("order"); // receipt.link names its order by the short string
    expect(subjectKindOf("stored_file")).toBe("stored_file");
    expect(subjectKindOf("App\\Modules\\Somewhere\\Models\\Thing")).toBeNull();
    expect(subjectKindOf("none")).toBe("none");
    expect(Object.values(SUBJECT_TYPES).every((type) => typeof type === "string" && type.length > 0)).toBe(true);
  });

  test("the drawer's diff: changed, added and removed fields first, unchanged after, the bookkeeping fields last and never highlighted", () => {
    const before = { id: 41, status: "recorded", amount: "70.00", voided_at: null, note: "kept", updated_at: "2026-10-27T10:00:00Z", old: "gone" };
    const after = { id: 41, status: "voided", amount: "70.00", voided_at: "2026-10-27T11:05:00Z", note: "kept", updated_at: "2026-10-27T11:05:00Z", fresh: "new" };
    const rows = diffOf(before, after);
    expect(shape(rows)).toEqual([
      ["fresh", "added"],
      ["old", "removed"],
      ["status", "changed"],
      ["voided_at", "changed"],
      ["amount", "same"],
      ["note", "same"],
      ["id", "same"],
      ["updated_at", "changed"],
    ]);
    expect(rows.filter((row) => row.bookkeeping).map((row) => row.key)).toEqual(["id", "updated_at"]);
    const status = rows.find((row) => row.key === "status");
    expect(status?.before).toEqual({ kind: "text", parts: [{ text: "recorded" }] });
    expect(status?.after).toEqual({ kind: "text", parts: [{ text: "voided" }] });
    expect(rows.find((row) => row.key === "fresh")?.before).toEqual({ kind: "missing" });
    expect(rows.find((row) => row.key === "voided_at")?.before).toEqual({ kind: "null" });
    // a field missing on one side counts as null there, as the API's summary counts it
    expect(shape(diffOf({ a: null }, { b: null }))).toEqual([
      ["a", "same"],
      ["b", "same"],
    ]);
    // a create (nothing before) lists what was written as added; numbers and booleans read as JSON writes them
    expect(shape(diffOf(null, { qty: "2.0000", is_enabled: true, sort: 3 }))).toEqual([
      ["is_enabled", "added"],
      ["qty", "added"],
      ["sort", "added"],
    ]);
    expect(diffOf(null, { is_enabled: true })[0].after).toEqual({ kind: "scalar", text: "true" });
    expect(diffOf(null, { sort: 3 })[0].after).toEqual({ kind: "scalar", text: "3" });
    expect(diffOf(null, null)).toEqual([]);
  });

  test("a redacted value shows as the marker, never as data; it is changed only when it went from nothing to set or back", () => {
    const rows = diffOf({ reference_no: REDACTED, receipt_file_id: null, pin: REDACTED }, { reference_no: REDACTED, receipt_file_id: REDACTED, pin: REDACTED });
    expect(shape(rows)).toEqual([
      ["receipt_file_id", "changed"],
      ["pin", "same"],
      ["reference_no", "same"],
    ]);
    expect(rows.find((row) => row.key === "reference_no")?.after).toEqual({ kind: "redacted" });
    expect(rows.find((row) => row.key === "receipt_file_id")?.before).toEqual({ kind: "null" });
    // a signed URL or a file key redacted where it stood inside a text
    expect(textParts(`photo at ${REDACTED} for order 17`)).toEqual([{ text: "photo at " }, { redacted: true }, { text: " for order 17" }]);
    expect(textParts(`${REDACTED}${REDACTED}`)).toEqual([{ redacted: true }, { redacted: true }]);
    expect(shownOf("plain")).toEqual({ kind: "text", parts: [{ text: "plain" }] });
    expect(shownOf("")).toEqual({ kind: "text", parts: [] });
    expect(shownOf(REDACTED)).toEqual({ kind: "redacted" });
    // inside a list too
    expect(shownOf(["cash", REDACTED])).toEqual({ kind: "list", parts: [{ text: '["cash", ' }, { redacted: true }, { text: "]" }] });
  });

  test("nested objects are folded and diffed inside; one holding a change opens; lists are shown and compared whole", () => {
    const rows = diffOf(
      { meta: { a: 1, b: { c: "x" } }, tags: ["a", "b"], same: { k: 1 }, lines: [{ id: 1, qty: "1.0000" }] },
      { meta: { a: 1, b: { c: "y" } }, tags: ["b", "a"], same: { k: 1 }, lines: [{ qty: "1.0000", id: 1 }] },
    );
    expect(shape(rows)).toEqual([
      ["meta", "changed", [["b", "changed", [["c", "changed"]]], ["a", "same"]]],
      ["tags", "changed"],
      ["lines", "same"],
      ["same", "same", [["k", "same"]]],
    ]);
    expect(rows.map(foldOpen)).toEqual([true, true, false, false]);
    expect(rows.find((row) => row.key === "tags")?.after).toEqual({ kind: "list", parts: [{ text: '["b", "a"]' }] });
    expect(rows.find((row) => row.key === "meta")?.after).toMatchObject({ kind: "object" });
    // an object on one side only is shown folded with its fields
    const added = diffOf({}, { context: { reason: "x", by: REDACTED } })[0];
    expect(added.status).toBe("added");
    expect(added.fields).toBeUndefined();
    expect(added.after).toEqual({
      kind: "object",
      rows: [
        { key: "reason", value: { kind: "text", parts: [{ text: "x" }] } },
        { key: "by", value: { kind: "redacted" } },
      ],
    });
    // the context and an event's payload read as one column, in the order they were written
    expect(valuesOf({ reason: "wrong transfer", approved_by_user_id: 12, order_id: 17 }).map((row) => row.key)).toEqual(["reason", "approved_by_user_id", "order_id"]);
    expect(valuesOf(null)).toEqual([]);
    expect(valuesOf(["not", "an", "object"])).toEqual([{ key: "0", value: { kind: "text", parts: [{ text: "not" }] } }, { key: "1", value: { kind: "text", parts: [{ text: "an" }] } }, { key: "2", value: { kind: "text", parts: [{ text: "object" }] } }]);
  });

  test("a field that names a person is named from the people list; the actor filter offers the list and everyone seen on the page", () => {
    expect(personFieldId("approved_by_user_id", 12)).toBe(12);
    expect(personFieldId("user_id", 3)).toBe(3);
    expect(personFieldId("acting_user_id", 4)).toBe(4);
    expect(personFieldId("actor_id", 5)).toBe(5);
    expect(personFieldId("approved_by_user_id", REDACTED)).toBeNull();
    expect(personFieldId("order_id", 17)).toBeNull();
    expect(personFieldId("user_id", 0)).toBeNull();

    const rows = [entry(3, { id: 9, name: "Till account" }, { id: 4, name: "Abebe" }), entry(2, { id: 5, name: null })];
    // a manager has no people list (GET /users is people.manage): the names on the page, and the chosen id kept even when nobody on it has it
    expect(peopleOptions([], rows, "77")).toEqual([
      { id: 4, name: "Abebe" },
      { id: 9, name: "Till account" },
      { id: 5, name: null },
      { id: 77, name: null },
    ]);
    // an admin's list wins for the names; nobody twice
    expect(peopleOptions([{ id: 5, name: "Hana" }, { id: 4, name: "Abebe K." }], rows, "")).toEqual([
      { id: 4, name: "Abebe K." },
      { id: 5, name: "Hana" },
      { id: 9, name: "Till account" },
    ]);
  });

  test("the export is named in the browser as the API names it; a file too large says how many rows it would hold", () => {
    expect(auditExportFileName(NO_AUDIT_FILTERS, "csv", "2026-10-27")).toBe("audit-log-all-2026-10-27.csv");
    expect(auditExportFileName({ ...NO_AUDIT_FILTERS, from: "2026-10-01", to: "2026-10-07" }, "xlsx", "2026-10-27")).toBe("audit-log-2026-10-01-2026-10-07.xlsx");
    expect(auditExportFileName({ ...NO_AUDIT_FILTERS, from: "2026-10-01" }, "csv", "2026-10-27")).toBe("audit-log-2026-10-01-2026-10-27.csv");
    expect(tooLargeOf({ status: 422, key: "audit.export.too_large", body: { message_key: "audit.export.too_large", max_rows: 50000, rows: 61234 } })).toEqual({ maxRows: 50000, rows: 61234 });
    expect(tooLargeOf({ status: 422, key: "validation.failed", body: {} })).toBeNull();
    expect(tooLargeOf(new Error("network"))).toBeNull();
  });

  test("the first page with the older pages: 4.5c's fold, over audit rows", () => {
    const row = (id: number) => entry(id, { id: 1, name: "Admin" });
    let pages: ListPages<AuditEntry> = { head: [row(10), row(9), row(8)], older: [] };
    expect(olderCursor(pages, "8")).toBe("8");
    pages = { ...pages, older: [row(7), row(6)] };
    expect(olderCursor(pages, "8")).toBe("6");
    // Refresh: two new rows push 9 and 8 out of the first page; they stay above the older pages, nothing goes missing between the two
    pages = foldHead(pages, [row(12), row(11), row(10)]);
    expect(visibleRows(pages).map((r) => r.id)).toEqual([12, 11, 10, 9, 8, 7, 6]);
    expect(olderCursor(pages, "10")).toBe("6");
    // a first page that comes back empty means nothing matches any more
    expect(foldHead(pages, [])).toEqual({ head: [], older: [] });
  });
});
