// Response and request shapes of the endpoints the Phase 0 shell calls, typed by hand from docs/openapi.json
// (components.schemas.*Resource / Store*Request) and the PHP resources behind it, which are more precise than the
// generated spec in places (`kind` and `fulfilment` are enums; POST bodies echo the resource). Money and quantities are
// decimal strings end to end (ADR 0004): never parse them into numbers.

export type Locale = "en" | "am";
export type ItemKind = "ingredient" | "product";
export type Fulfilment = "to_order" | "to_stock" | "bought_in";
export type Role = "admin" | "manager" | "cashier" | "waiter" | "chef" | "barista" | "cake_baker" | "bread_baker";

export const ROLES: readonly Role[] = ["admin", "manager", "cashier", "waiter", "chef", "barista", "cake_baker", "bread_baker"];
export const FULFILMENTS: readonly Fulfilment[] = ["to_order", "to_stock", "bought_in"];

/** A decimal carried as a string, e.g. "12.5000" (ADR 0004). */
export type DecimalString = string;

export interface User {
  id: number;
  branch_id: number;
  name: string;
  phone: string;
  email: string | null;
  locale: Locale;
  is_active: boolean;
  photo_url: string | null;
  has_short_code: boolean;
  roles: Role[];
  permissions_direct: string[];
  stations: UserStation[];
  created_at: string | null;
  updated_at: string | null;
}

/** POST /login */
export interface LoginResponse {
  token: string;
  token_type: "Bearer";
  user: User;
  permissions: string[];
  locale: Locale;
}

/** GET /me */
export interface Me {
  user: User;
  permissions: string[];
  locale: Locale;
  acting: null | {
    session: { id: number; started_at: string; expires_at: string };
    user: { id: number; name: string; photo_url: string | null; locale: Locale };
  };
  device: null | { id: number };
  /** The stations this person may work at (every station for stations.all), each with the shift running there (step 3.1a). */
  work_stations: WorkStation[];
}

export type ShiftStatus = "scheduled" | "opening" | "open" | "counting" | "pending_approval" | "locked" | "cancelled";

/** GET /me work_stations: a station the person works at and, when it tracks shifts, the one running there. */
export interface WorkStation {
  station_id: number;
  code: StationCode;
  name_en: string;
  name_am: string;
  tracks_shifts: boolean;
  requires_overseer: boolean;
  current_shift: null | { id: number; status: ShiftStatus; date: string; opened_at: string | null };
}

export interface RosterRow {
  user: { id: number; name: string } | null;
  source: "scheduled" | "started" | "joined" | "call_in";
  joined_at: string | null;
  left_at: string | null;
}

/** A count of a shift as the shift lists it (the count screen of step 3.7b reads the whole count). */
export interface ShiftCountSummary {
  id: number;
  type: "opening" | "closing";
  status: string;
  location: { id: number; name_en: string; name_am: string; type: string };
}

/** GET /stations/{station}/shifts/current and the shift endpoints (phase-3 plan section 4, "Resources"). */
export interface Shift {
  id: number;
  client_uuid: string;
  station: { id: number; code: StationCode; name_en: string; name_am: string; tracks_shifts: boolean; requires_overseer: boolean };
  template: { id: number; name_en: string; name_am: string; start_time: string; end_time: string } | null;
  date: string;
  status: ShiftStatus;
  origin: string;
  opening_mode: "counted" | "carried" | null;
  starts_at: string | null;
  ends_at: string | null;
  started_by: { id: number; name: string } | null;
  started_at: string | null;
  opened_by: { id: number; name: string } | null;
  opened_at: string | null;
  closed_by: { id: number; name: string } | null;
  closing_started_at: string | null;
  me_on_roster: boolean;
  version: number;
  roster: RosterRow[];
  counts: ShiftCountSummary[];
  notes_count: number;
}

export interface ShiftCurrentPage {
  data: Shift | null;
  server_time: string;
}

export interface Unit {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
  base_unit_id: number | null;
  base_unit_code: string | null;
  factor: DecimalString;
  is_base: boolean;
}

export interface Category {
  id: number;
  branch_id: number;
  kind: ItemKind;
  name_en: string;
  name_am: string;
  parent_id: number | null;
  sort: number;
  deleted_at: string | null;
}

export interface Item {
  id: number;
  branch_id: number;
  kind: ItemKind;
  fulfilment: Fulfilment;
  name_en: string;
  name_am: string;
  category_id: number | null;
  unit_id: number;
  unit_code: string;
  base_unit_code: string | null;
  sku: string | null;
  is_active: boolean;
  is_new_until: string | null;
  is_new: boolean;
  default_batch_qty: DecimalString | null;
  production_station_id: number | null;
  station_ids: number[];
  /** step 4.1b (Ramin's rule R-C): whether the product may be refunded at all */
  refundable: boolean;
  /** step 4.1b: whether a refunded unit goes back into stock (only a product sold from stock or bought in may) */
  refund_restock: boolean;
  created_by_user_id: number;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/** Laravel resource collections */
export interface Collection<T> {
  data: T[];
}

/** POST /items (StoreItemRequest) */
export interface NewItem {
  kind: ItemKind;
  fulfilment?: Fulfilment;
  name_en: string;
  name_am: string;
  category_id?: number | null;
  unit_id: number;
  sku?: string | null;
  /** step 4.5g: the two refund settings; left out, the API gives the natural values (refundable; back into stock when sold from stock) */
  refundable?: boolean;
  refund_restock?: boolean;
}

/** PATCH /items/{item}: the refund settings the product page changes (step 4.5g). */
export interface ItemRefundSettings {
  refundable: boolean;
  refund_restock: boolean;
}

/** POST /categories (StoreCategoryRequest) */
export interface NewCategory {
  kind: ItemKind;
  name_en: string;
  name_am: string;
}

/** POST /users (StoreUserRequest) */
export interface NewUser {
  name: string;
  phone: string;
  password: string;
  role: Role;
  locale: Locale;
}

/** Every API error (rule 9): a stable key with its English and Amharic messages; 422 adds the field errors. */
export interface ApiErrorBody {
  message_key: string;
  message_en?: string;
  message_am?: string;
  errors?: Record<string, string[]>;
}

// ---------------------------------------------------------------------------------------------------------------
// Phase 1 (step 1.6a): orders, the menu, tables, tickets and payments. Typed by hand from docs/openapi.json and the
// PHP resources behind it (Orders\Http\Resources, Orders\Contracts\Tickets, Payments\Contracts\ChannelView).
// ---------------------------------------------------------------------------------------------------------------

export type StationCode = "till" | "kitchen" | "bar" | "cake_room" | "bakery" | "store";
export type OrderType = "dine_in" | "takeaway";
export type OrderStatus = "open" | "sent" | "in_progress" | "ready" | "served" | "paid" | "closed" | "cancelled" | "voided";
export type LineStatus = "queued" | "accepted" | "ready" | "served" | "rejected" | "voided";
export type RejectReason = "ingredient_spoiled" | "equipment_down" | "out_of_stock" | "other";
export type TableState = "free" | "ordering" | "waiting" | "served" | "bill";

/** The four reasons a station gives a line back, in the order the reject picker lists them. */
export const REJECT_REASONS: readonly RejectReason[] = ["ingredient_spoiled", "equipment_down", "out_of_stock", "other"];

/** A station of the user: GET /me and GET /users carry the code and both names, so a chef needs no admin endpoint. */
export interface UserStation {
  station_id: number;
  code: StationCode;
  name_en: string;
  name_am: string;
  can_count: boolean;
  is_overseer: boolean;
}

/** GET /stations (people.manage) */
export interface Station {
  id: number;
  branch_id: number;
  code: StationCode;
  name_en: string;
  name_am: string;
  requires_overseer: boolean;
  sort: number;
}

export interface LineModifier {
  id: number;
  name_en: string;
  name_am: string;
  price_delta: DecimalString;
}

export interface Line {
  id: number;
  client_uuid: string;
  item: { id: number; name_en: string; name_am: string };
  qty: DecimalString;
  unit_price: DecimalString;
  modifier_price: DecimalString;
  modifiers: LineModifier[];
  line_total: DecimalString;
  station: { id: number; code: StationCode; name_en: string; name_am: string } | null;
  status: LineStatus;
  recipe_version: number | null;
  accepted_by: number | null;
  accepted_at: string | null;
  prepared_by: number | null;
  ready_at: string | null;
  served_by: number | null;
  served_at: string | null;
  rejected: { reason: RejectReason; note: string | null; message_en: string; message_am: string; by: number | null; at: string | null } | null;
  notes: string | null;
  /** step 4.1b: when the line was refunded (a line is refunded once); absent in answers older than the refund */
  refunded_at?: string | null;
  version: number;
}

export interface OrderTotals {
  subtotal: DecimalString;
  discount: DecimalString;
  tax: DecimalString;
  total: DecimalString;
  paid: DecimalString;
  balance: DecimalString;
  /** step 4.1b: what came back of the money (paid and balance do not change with a refund) and what the order keeps */
  refunded?: DecimalString;
  net_paid?: DecimalString;
}

/** One refund of an order as GET /orders/{order} lists it (step 4.1b), oldest first. */
export interface OrderRefund {
  id: number;
  amount: DecimalString;
  is_full: boolean;
  reason: string;
  requested_by: number;
  approved_by: number;
  at: string;
  lines: { order_line_id: number; qty: DecimalString; amount: DecimalString; disposition: "restock" | "waste"; movement_ids: number[] }[];
}

export interface Order {
  id: number;
  client_uuid: string;
  order_no: string | number;
  business_date: string | null;
  type: OrderType;
  table_code: string | null;
  dining_table: { id: number; code: string; name_en: string; name_am: string } | null;
  status: OrderStatus;
  created_via: string;
  owner: { id: number; name: string | null };
  created_by_user_id: number;
  acting_user_id: number | null;
  notes: string | null;
  totals: OrderTotals;
  currency: string;
  sent_at: string | null;
  settled_at: string | null;
  paid_at: string | null;
  closed_at: string | null;
  cancelled: { reason: string | null; at: string } | null;
  voided: { reason: string; by: number | null; approved_by: number | null; at: string } | null;
  version: number;
  created_at: string | null;
  lines: Line[];
  /** step 4.1b: the money that came back, when the last refund was made and every refund; absent in answers older than the refund */
  refunded_total?: DecimalString;
  refunded_at?: string | null;
  refunds?: OrderRefund[];
}

/** GET /orders?since= and ?mine= */
export interface OrdersPage {
  data: Order[];
  cursor: string;
  server_time: string;
}

/** The order header a kitchen ticket carries (Orders\Contracts\Tickets). */
export interface TicketOrder {
  id: number;
  order_no: string | number;
  type: OrderType;
  table_code: string | null;
  owner: { id: number; name: string | null };
  sent_at: string | null;
  notes: string | null;
}

/** An order with its lines at one station. */
export interface Ticket {
  order: TicketOrder;
  lines: Line[];
}

/** GET /stations/{station}/tickets */
export interface TicketsPage {
  data: Ticket[];
  cursor: string;
  server_time: string;
}

export interface MenuModifier {
  id: number;
  name_en: string;
  name_am: string;
  price_delta: DecimalString;
}

export interface MenuItem {
  item_id: number;
  name_en: string;
  name_am: string;
  category_id: number | null;
  unit: string;
  price: DecimalString;
  currency: string;
  available: DecimalString;
  low: boolean;
  is_new: boolean;
  station_id: number | null;
  location_id: number | null;
  modifiers: MenuModifier[];
  unavailable_reason?: "out_of_stock" | "no_location";
}

/** GET /menu */
export interface Menu {
  categories: { id: number; name_en: string; name_am: string; sort: number }[];
  items: MenuItem[];
  generated_at: string;
}

/** GET /dining-tables */
export interface DiningTable {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
  seats: number | null;
  sort: number;
  is_active: boolean;
  state: TableState;
}

/** GET /payment-channels: the Phase 1 keys, then the rules of step 4.3a (Payments\Contracts\ChannelView). */
export interface PaymentChannel {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
  is_enabled: boolean;
  requires_photo: boolean;
  requires_reference: boolean;
  sort: number;
  /** since step 4.3b simply is_enabled (a photo channel is taken with its photo) */
  accepted_now: boolean;
  /** a regex body matched against the whole reference by the API (ReferencePattern); null: any text */
  reference_pattern: string | null;
  reference_hint_en: string | null;
  reference_hint_am: string | null;
  photo_hint_en: string | null;
  photo_hint_am: string | null;
  is_cash: boolean;
  deleted_at: string | null;
}

export interface Payment {
  id: number;
  client_uuid: string;
  channel_code: string;
  amount: DecimalString;
  tendered_amount: DecimalString | null;
  change_amount: DecimalString | null;
  currency: string;
  status: string;
  received_by: number;
  received_at: string;
}

/** POST /orders/{order}/payments */
export interface PaymentResult {
  payment: Payment;
  order: { id: number; status: OrderStatus; total: DecimalString; paid: DecimalString; balance: DecimalString };
  warnings: PaymentWarning[];
}

/** A payment as the writes answer it (step 4.3b adds the photo's id and the batch). */
export interface RecordedPaymentRow extends Payment {
  receipt_file_id: number | null;
  batch_uuid: string | null;
}

/** A reference or a receipt photo seen on another payment (step 4.3b): shown to the cashier, never a refusal. */
export interface PaymentWarning {
  code: "reference.duplicate" | "receipt.duplicate" | string;
  message_key: string;
  message_en: string;
  message_am: string;
  payment_id: number;
  payment_ids: number[];
}

/** POST /orders/{order}/payment-batches body (step 4.3b): 1 to 6 payments that add up to exactly the balance, all or none. */
export interface NewPaymentBatch {
  client_uuid: string;
  rows: {
    client_uuid: string;
    channel_code: string;
    amount: DecimalString;
    tendered_amount?: DecimalString;
    reference_no?: string;
    receipt_file_id?: number;
  }[];
}

/** POST /orders/{order}/payment-batches */
export interface PaymentBatchResult {
  payments: RecordedPaymentRow[];
  order: PaymentResult["order"];
  warnings: PaymentWarning[];
}

/** POST /files (step 4.2b): a stored picture; `url` is signed and short-lived. */
export interface StoredFileView {
  id: number;
  purpose: "receipt" | "refund_proof";
  width: number;
  height: number;
  bytes: number;
  sha256: string;
  url: string;
  url_expires_at: string;
  attached: boolean;
}

/** A payment as the reads answer it (GET /orders/{order}/payments, step 4.3b). */
export interface PaymentView {
  id: number;
  order_id: number;
  client_uuid: string;
  channel: { id: number; code: string | null; name_en: string | null; name_am: string | null };
  amount: DecimalString;
  tendered_amount: DecimalString | null;
  change_amount: DecimalString | null;
  currency: string;
  reference_no: string | null;
  status: "recorded" | "verified" | "disputed" | "voided";
  received_at: string;
  received_by: { id: number; name: string | null };
  shift_id: number | null;
  batch_uuid: string | null;
  receipt: { file_id: number; url: string | null; url_expires_at: string | null } | null;
  verified_at: string | null;
  disputed_at: string | null;
  dispute_reason: string | null;
  refunded_amount: DecimalString;
  void_reason: string | null;
  voided_at: string | null;
  flags: { duplicate_reference: boolean; duplicate_receipt: boolean };
}

/** GET /orders/{order}/payments */
export interface OrderPayments {
  data: PaymentView[];
  balance: DecimalString;
}

export type PaymentStatus = PaymentView["status"];

export const PAYMENT_STATUSES: readonly PaymentStatus[] = ["recorded", "verified", "disputed", "voided"];

/** A payment as GET /payments lists it (step 4.3b): the read shape with its order (numbers restart daily). */
export interface PaymentListRow extends PaymentView {
  order: { id: number; order_no: number | null; business_date: string | null; table_code: string | null; total: DecimalString } | null;
}

/** GET /payments: one page, newest first; `next_cursor` is the last id seen, null on the last page. */
export interface PaymentsPage {
  data: PaymentListRow[];
  next_cursor: string | null;
  server_time: string;
}

/** A payment's refund as GET /payments/{payment} lists it (none before step 4.3d). */
export interface PaymentRefundView {
  id: number;
  amount: DecimalString;
  channel_id: number;
  refunded_at: string;
}

/** GET /payments/{payment}, and the answer of verify, dispute and void (step 4.3c). */
export interface PaymentDetail extends PaymentListRow {
  refunds: PaymentRefundView[];
}

/** POST /orders/{order}/refund (step 4.3d): no `lines` refunds every refundable line; no `allocation` gives back last in, first out. */
export interface RefundBody {
  client_uuid: string;
  reason: string;
  pin: string;
  lines?: number[];
  allocation?: { payment_id: number; amount: DecimalString }[];
  refund_reference?: string;
  refund_proof_file_id?: number;
}

/** The 201 (and the 200 of a replay) of POST /orders/{order}/refund. */
export interface RefundResult {
  refund: {
    id: number;
    order_id: number;
    amount: DecimalString;
    is_full: boolean;
    reason: string;
    requested_by_user_id: number;
    approved_by_user_id: number;
    lines: { line_id: number; item_id: number; qty: DecimalString; amount: DecimalString; disposition: "restock" | "waste"; movement_ids: number[] }[];
  };
  payments: {
    id: number;
    payment_id: number;
    channel_code: string;
    channel_name_en: string;
    channel_name_am: string;
    amount: DecimalString;
    reference_no: string | null;
    proof_file_id: number | null;
    shift_instance_id: number | null;
    refunded_at: string;
  }[];
  movements: { id: number; item_id: number; location_id: number; qty: DecimalString; reverses: number }[];
  order: { id: number; status: OrderStatus; total: DecimalString; paid: DecimalString; balance: DecimalString; refunded: DecimalString; net_paid: DecimalString };
}

/** GET /files/{file}/url (step 4.2b): a fresh signed URL of a stored photo. */
export interface SignedFileUrl {
  url: string;
  expires_at: string;
}

/** One local day and channel of GET /payments/reconciliation (step 4.3e), and the shape of its totals. */
export interface ReconciliationCell {
  receipts: number;
  amount: DecimalString;
  with_photo: number;
  without_photo: number;
  with_reference: number;
  verified: number;
  disputed: number;
  voided: number;
  refunds: number;
  refund_amount: DecimalString;
  net_amount: DecimalString;
}

export interface ReconciliationChannelRow extends ReconciliationCell {
  channel: { id: number; code: string; name_en: string; name_am: string };
}

/** GET /payments/reconciliation: every day of the range, oldest first; a day lists the channels that had a payment or a refund. */
export interface Reconciliation {
  from: string;
  to: string;
  timezone: string;
  days: { day: string; channels: ReconciliationChannelRow[] }[];
  totals: ReconciliationCell & { channels: ReconciliationChannelRow[] };
}

/** POST /payment-channels body (step 4.3a). */
export interface NewPaymentChannel {
  client_uuid: string;
  code: string;
  name_en: string;
  name_am: string;
  is_enabled: boolean;
  requires_photo: boolean;
  requires_reference: boolean;
  reference_pattern: string | null;
  reference_hint_en: string | null;
  reference_hint_am: string | null;
  photo_hint_en: string | null;
  photo_hint_am: string | null;
}

/** PATCH /payment-channels/{channel} body: any of these; the code never changes. */
export type PaymentChannelChanges = Partial<Omit<NewPaymentChannel, "client_uuid" | "code">>;

/** PUT /receipt-settings body (step 4.3e): the whole form each time. */
export type ReceiptSettingsInput = Omit<ReceiptSettings, "is_default">;

/** GET /receipt-settings (step 4.3e): what the printed receipt carries at its top. */
export interface ReceiptSettings {
  business_name_en: string;
  business_name_am: string;
  address_en: string | null;
  address_am: string | null;
  phone: string | null;
  tin: string | null;
  footer_en: string | null;
  footer_am: string | null;
  show_amharic: boolean;
  ethiopian_dates: boolean;
  is_default: boolean;
}

/** POST /orders/{order}/receipt-link (step 4.3e): a signed link to the public receipt. */
export interface ReceiptLink {
  url: string;
  expires_at: string;
}

/** POST /orders and POST /orders/{order}/lines body: one line of the cart. */
export interface NewOrderLine {
  client_uuid: string;
  item_id: number;
  qty: DecimalString;
  modifier_ids?: number[];
  notes?: string | null;
}

/** POST /orders */
export interface NewOrder {
  client_uuid: string;
  type: OrderType;
  dining_table_id?: number | null;
  notes?: string | null;
  send?: boolean;
  lines: NewOrderLine[];
}

/** POST /order-lines/{line}/reject */
export interface RejectBody {
  reason: RejectReason;
  note?: string | null;
}

/** One short ingredient of a refused send (409 orders.send.shortfall). */
export interface ShortfallLine {
  line_client_uuid: string;
  line_id: number;
  item_id: number;
  item_name_en: string | null;
  item_name_am: string | null;
  qty: DecimalString;
  ingredient_item_id: number;
  ingredient_name_en: string | null;
  ingredient_name_am: string | null;
  needed: DecimalString;
  available: DecimalString;
  qty_short: DecimalString;
  unit: string;
  qty_short_display: string;
  location_id: number | null;
  station_id: number | null;
  station_name_en: string | null;
  station_name_am: string | null;
  message_en: string;
  message_am: string;
}

/** The body of 409 orders.send.shortfall: the order stays open, nothing was written. */
export interface ShortfallBody extends ApiErrorBody {
  order: Order;
  shortfall: ShortfallLine[];
}

// ---------------------------------------------------------------------------------------------------------------
// Step 1.6b: the waiter pad.
// ---------------------------------------------------------------------------------------------------------------

/** POST /short-code/verify: the person whose code it was, and the 10-minute acting session. */
export interface ShortCodeVerified {
  user: { id: number; name: string; photo_url: string | null; locale: Locale };
  permissions: string[];
  acting_session: { id: number; started_at: string; expires_at: string };
}

/** The 422 body of a refused line: `line_client_uuid` says which line it is about (orders.item.not_sellable ...). */
export interface LineErrorBody extends ApiErrorBody {
  line_client_uuid?: string;
  field?: string;
}

// ---------------------------------------------------------------------------------------------------------------
// Step 1.6c: the cashier till and the admin Orders board.
// ---------------------------------------------------------------------------------------------------------------

/** One row of an order's timeline (GET /orders/{order} `events`, oldest first). */
export interface OrderEvent {
  id: number;
  event: string;
  order_line_id: number | null;
  station_id: number | null;
  user_id: number | null;
  acting_user_id: number | null;
  approved_by_user_id: number | null;
  payload: Record<string, unknown> | null;
  at: string;
}

/** GET /orders/{order}: the order with its timeline. */
export interface OrderDetail extends Order {
  events: OrderEvent[];
}

/** POST /orders/{order}/payments body (cash only until Phase 4). */
export interface NewPayment {
  client_uuid: string;
  channel_code: string;
  amount: DecimalString;
  tendered_amount?: DecimalString;
}

/** POST /orders/{order}/void body: the reason and the PIN (short code) of a manager or admin. */
export interface VoidBody {
  reason: string;
  pin: string;
}

// ---------------------------------------------------------------------------------------------------------------
// Phase 2 (step 2.8a): the notification inbox and the production board. Typed by hand from docs/openapi.json and the
// PHP resources (NotificationResource, RequestPresenter, RequestCard).
// ---------------------------------------------------------------------------------------------------------------

export type NotificationSeverity = "info" | "warning" | "critical";

/** One inbox line (GET /notifications): title and body in the caller's language plus all four texts. */
export interface AppNotification {
  id: number;
  type: string;
  severity: NotificationSeverity;
  title: string;
  body: string;
  title_en: string;
  title_am: string;
  body_en: string;
  body_am: string;
  payload: Record<string, unknown> | null;
  deep_link: string | null;
  read_at: string | null;
  created_at: string;
}

/** GET /notifications?since=: newest first, the cursor to send next, and the unread count. */
export interface NotificationsPage {
  data: AppNotification[];
  unread_count: number;
  cursor: string;
  server_time: string;
}

/** GET /notifications/channels: what the web may offer. A channel whose driver is off is `enabled: false`. */
export interface NotificationChannels {
  in_app: true;
  push: { enabled: boolean; public_key: string | null; subscribed: boolean };
  telegram: { enabled: boolean; bot_username: string | null; linked: boolean };
}

export type RequestStatus = "requested" | "accepted" | "in_progress" | "pending_confirmation" | "done" | "cancelled";
export type BatchStatus = "started" | "finished" | "confirmed";
export type RequestPriority = "normal" | "high" | "urgent";

/** The priorities in the order a planner lists them. */
export const REQUEST_PRIORITIES: readonly RequestPriority[] = ["normal", "high", "urgent"];
export type RequestReason = "stock_out" | "manual" | "schedule";

export interface PersonRef {
  id: number;
  name: string;
}

export interface ProductionBatch {
  id: number;
  client_uuid: string;
  status: BatchStatus;
  recipe_version: number;
  planned_qty: DecimalString;
  produced_qty: DecimalString | null;
  waste_qty: DecimalString | null;
  started_at: string;
  finished_by: PersonRef | null;
  finished_at: string | null;
  confirmed_by: PersonRef | null;
  confirmed_at: string | null;
  send_back_note: string | null;
  send_back_count: number;
  version: number;
}

export interface ProductionRequest {
  id: number;
  client_uuid: string;
  product: { id: number; name_en: string; name_am: string; unit: string };
  requested_qty: DecimalString;
  due_at: string | null;
  priority: RequestPriority;
  reason: RequestReason;
  status: RequestStatus;
  station: { id: number; code: StationCode; name_en: string; name_am: string; requires_overseer: boolean };
  requested_by: PersonRef | null;
  assigned_to: PersonRef | null;
  note: string | null;
  accepted_at: string | null;
  done_at: string | null;
  cancelled_at: string | null;
  cancel_reason: string | null;
  batch: ProductionBatch | null;
  created_at: string | null;
  version: number;
}

/** GET /production/requests?view=board */
export interface ProductionBoardPage {
  data: ProductionRequest[];
  counts: { requested: number; accepted: number; in_progress: number; pending_confirmation: number; done_today: number };
  server_time: string;
}

export interface CardLine {
  ingredient: { id: number; name_en: string; name_am: string; unit: string };
  needed: DecimalString;
  /** null when the station has no stock place: nothing can be said about stock that has no place */
  on_hand: DecimalString | null;
  available: DecimalString | null;
  short: boolean | null;
  short_qty: DecimalString | null;
  transfer_hint: { from_location_id: number; store_available: DecimalString } | null;
}

export interface CardStep {
  order: number;
  text_en: string;
  text_am: string;
}

export interface CardPlace {
  id: number;
  name_en: string;
  name_am: string;
}

/** GET /production/requests/{request}: the request with its scaled recipe and the two places of the batch. */
export interface ProductionCard extends ProductionRequest {
  recipe: {
    version: number;
    yield: { qty: DecimalString; unit: string };
    target_qty: DecimalString;
    lines: CardLine[];
    steps: CardStep[];
  } | null;
  consume_location: CardPlace | null;
  output_location: CardPlace | null;
}

/** The answer of Done, Confirm and Send back: the batch plus the request it belongs to in short. */
export interface BatchAnswer extends ProductionBatch {
  request_id: number;
  request: { id: number; status: RequestStatus; version: number };
}

/** One entry of the 409 `production.batch.ingredient_short` shortfall list. */
export interface IngredientShort {
  ingredient: { id: number; name_en: string; name_am: string; unit: string };
  needed: DecimalString;
  available: DecimalString;
  short: DecimalString;
  location_id: number;
  location: string;
}

/** POST /transfers body (the request sheet of the kitchen display's stock strip and of the production card). */
export interface NewTransfer {
  client_uuid: string;
  item_id: number;
  to_location_id: number;
  from_location_id?: number;
  qty: DecimalString;
  note?: string;
  production_request_id?: number;
}

// --- the station screens (Phase 2, step 2.8c) ---------------------------------------------------------------------

export type StockState = "ok" | "low" | "out";

/** One ingredient row of GET /stations/{station}/stock-strip: available is in the item's base unit. */
export interface StripRow {
  item: { id: number; name_en: string; name_am: string };
  unit: string;
  available: DecimalString;
  min_qty: DecimalString | null;
  state: StockState;
}

export interface StockStripPage {
  location_id: number | null;
  data: StripRow[];
}

/** One product row of GET /display-case. */
export interface CaseRow {
  item: { id: number; name_en: string; name_am: string };
  unit: string;
  on_hand: DecimalString;
  reserved: DecimalString;
  available: DecimalString;
  low: boolean;
  state: StockState;
  production_station_id: number | null;
  producible: boolean;
}

export interface DisplayCasePage {
  location_id: number | null;
  data: CaseRow[];
}

// --- admin Catalog and planning (Phase 2, step 2.8d) ---------------------------------------------------------------

/** One period of GET /items/{item}/prices. */
export interface ItemPrice {
  id: number;
  item_id: number;
  price: DecimalString;
  currency: string;
  effective_from: string;
  effective_to: string | null;
  is_current: boolean;
}

/** A row of GET /items/{item}/recipe/versions. */
export interface RecipeVersionRow {
  version: number;
  is_active: boolean;
  yield_qty: DecimalString;
  yield_unit: string;
  line_count: number;
  published_at: string | null;
  published_by: PersonRef | null;
  change_note: string | null;
}

export interface RecipeLine {
  id: number;
  ingredient_item_id: number;
  qty: DecimalString;
  unit_id: number;
  unit_code: string;
  qty_base: DecimalString;
  base_unit_code: string;
  is_optional: boolean;
  waste_pct: DecimalString;
}

export interface RecipeStep {
  id: number;
  step_order: number;
  text_en: string;
  text_am: string;
}

/** GET /items/{item}/recipe/versions/{version}, and the answer of PUT and POST. */
export interface Recipe {
  id: number | null;
  product_item_id: number;
  version: number | null;
  is_active: boolean;
  published_at: string | null;
  published_by: PersonRef | null;
  change_note: string | null;
  yield_qty: DecimalString | null;
  yield_unit_id: number | null;
  yield_unit_code: string | null;
  base_unit_code: string;
  lines: RecipeLine[];
  steps: RecipeStep[];
}

/** The body of PUT /items/{item}/recipe and (with change_note) of POST /items/{item}/recipe/versions. */
export interface RecipeSpec {
  yield_qty: DecimalString;
  yield_unit_id: number;
  lines: { ingredient_item_id: number; qty: DecimalString; unit_id: number; is_optional: boolean; waste_pct: DecimalString }[];
  steps: { text_en: string; text_am: string }[];
  change_note?: string;
}

export interface RecipeCostLine {
  ingredient: { id: number; name_en: string | null; name_am: string | null };
  qty_base: DecimalString;
  unit: string;
  waste_pct: DecimalString;
  unit_cost: DecimalString | null;
  line_cost: DecimalString | null;
  source: string;
}

/** GET /inventory/recipe-costs/{product}?version= (costs.view). Every figure is null while the roll-up is incomplete. */
export interface RecipeCost {
  product_id: number;
  version: number;
  yield: { qty: DecimalString; unit: string };
  complete: boolean;
  missing_cost_item_ids: number[];
  total_cost: DecimalString | null;
  cost_per_unit: DecimalString | null;
  price: DecimalString | null;
  margin_amount: DecimalString | null;
  margin_pct: DecimalString | null;
  lines: RecipeCostLine[];
}

/** POST /production/requests as the planner sends it. */
export interface NewScheduledRequest {
  client_uuid: string;
  product_item_id: number;
  qty?: DecimalString;
  due_at: string;
  priority: RequestPriority;
  note?: string;
}

// --- counts, handover and the approval queue (step 3.7b; phase-3 plan section 4, "Counts" and "Handover and replay") ------------
// Quantities are decimal strings in the item's base unit and are shown as the API sends them; null where not yet known.

export type CountStatus = "draft" | "awaiting_cosign" | "pending_approval" | "approved" | "locked" | "rejected";

export interface CountReason {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
}

/** GET /variance-reasons */
export interface VarianceReason extends CountReason {
  requires_note: boolean;
  is_active: boolean;
  sort: number;
}

export interface CountLine {
  id: number;
  item: { id: number; name_en: string; name_am: string; unit: string };
  expected_qty: string | null;
  carried_qty: string | null;
  counted_qty: string | null;
  variance_qty: string | null;
  /** the counter has touched the line (typed a figure or pressed "as expected") */
  confirmed: boolean;
  reason: CountReason | null;
  note: string | null;
  reason_status: "none" | "pending" | "accepted" | "rejected";
  decision_note: string | null;
  adjustment_movement_id: number | null;
  /** the net change at the place since the count (or since the previous closing for an opening draft); null where not measured */
  moved_since: string | null;
}

export interface CountChain {
  chain_seq: number;
  previous_hash: string | null;
  payload_hash: string;
  locked_at: string;
}

/** One channel of the reconciliation: what Payments took in the shift against what the cashier declared (null where nothing was declared). */
export interface CashChannelRow {
  code: string;
  name_en: string;
  name_am: string;
  expected_receipts: number;
  expected_sum: string;
  declared_receipts: number | null;
  declared_sum: string | null;
  difference_sum: string | null;
}

/** The cash drawer's declaration on a drawer count (phase-3 plan section 4, "Cash (3.5)"). Money is decimal strings, tallies whole numbers. */
export interface CashBlock {
  /** every configured denomination, zero where none: {"200": 3, "0.50": 1} */
  denominations: Record<string, number>;
  counted_total: string;
  expected_total: string;
  variance_total: string;
  carried_float_total: string | null;
  cash_sales_total: string | null;
  float_left_total: string | null;
  channels: CashChannelRow[];
  confirmed: boolean;
  reason: { id: number; code: string; name_en: string; name_am: string } | null;
  note: string | null;
  currency: string;
}

/** GET /shifts/{shift}/cash: the till widget's figures, live from Payments. */
export interface CashSummary {
  opening_total: string;
  carried_float_total: string | null;
  cash_sales_total: string;
  expected_total: string;
  by_channel: { code: string; name_en: string; name_am: string; receipts: number; sum: string }[];
  currency: string;
}

export interface CountPlace {
  id: number;
  name_en: string;
  name_am: string;
  type: string;
}

export interface CountSummary {
  id: number;
  shift_id: number;
  location: CountPlace | null;
  type: "opening" | "closing";
  seq: number;
  status: CountStatus;
  counted_by: { id: number; name: string } | null;
  started_at: string | null;
  snapshot_at: string | null;
  submitted_at: string | null;
  cosigned_by: { id: number; name: string } | null;
  decided_by: { id: number; name: string } | null;
  decision: "approved" | "rejected" | null;
  decision_note: string | null;
  supersede_cause: "recount" | "unlock" | null;
  superseded_by_id: number | null;
  chain: CountChain | null;
  blocked_by_pending: { count_id: number } | null;
  version: number;
  lines_count: number;
}

export interface Count extends Omit<CountSummary, "lines_count"> {
  lines: CountLine[];
  /** the cash drawer's declaration; null for a stock count */
  cash: CashBlock | null;
}

/** GET /stock-counts: a summary with what an approver needs without opening the count. */
export interface QueueRow extends CountSummary {
  station: { id: number; code: string; name_en: string; name_am: string; requires_overseer: boolean };
  shift: { id: number; date: string; status: ShiftStatus };
  variance_lines: CountLine[];
}

export interface QueuePage {
  data: QueueRow[];
  counts: { awaiting_cosign: number; pending_approval: number };
  server_time: string;
}

export interface HandoverNote {
  id: number;
  client_uuid: string;
  shift_id: number;
  from: { id: number; name: string } | null;
  to: { id: number; name: string } | null;
  body: string;
  created_at: string;
}

export interface PreviousClosing {
  location: CountPlace;
  count_id: number;
  chain_seq: number | null;
  payload_hash: string | null;
  locked_at: string | null;
  provisional: boolean;
  float_left_total: string | null;
}

/** GET /stations/{station}/handover */
export interface HandoverPanelData {
  previous: Omit<Shift, "roster" | "counts" | "notes_count"> | null;
  notes: HandoverNote[];
  previous_closing: PreviousClosing[];
  pending_counts: CountSummary[];
}

// --- the admin shifts screens (step 3.7c; phase-3 plan section 4, "Templates and reasons", "Shifts", "Chain" and "Replay") ----------

/** GET /shift-templates: a window of the local day on some weekdays (ISO, 1 = Monday) at some stations. */
export interface ShiftTemplate {
  id: number;
  name_en: string;
  name_am: string;
  start_time: string;
  end_time: string;
  days_of_week: number[];
  station_ids: number[];
  is_active: boolean;
  sort: number;
  version: number;
}

/** GET /shifts: a shift as the list shows it (no roster, counts or note count). */
export type ShiftSummary = Omit<Shift, "roster" | "counts" | "notes_count"> & {
  submitted_at?: string | null;
  locked_at?: string | null;
  cancelled_at?: string | null;
  cancel_reason?: string | null;
};

export interface ShiftPage {
  data: ShiftSummary[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

/** GET /locations (inventory.view) */
export interface StockPlace {
  id: number;
  name_en: string;
  name_am: string;
  type: string;
  station_id: number | null;
}

export interface ChainLink {
  count_id: number;
  chain_seq: number;
  type: "opening" | "closing";
  shift_id: number;
  shift_date: string;
  locked_at: string;
  previous_hash: string | null;
  payload_hash: string;
  superseded_by_id: number | null;
}

export interface ChainPage {
  data: ChainLink[];
  head: { chain_seq: number; payload_hash: string } | null;
}

export type ChainFailureKind = "gap" | "previous_mismatch" | "hash_mismatch" | "payload_mismatch";

export interface ChainVerify {
  ok: boolean;
  checked: number;
  head_hash: string | null;
  failures: { chain_seq: number; count_id: number; kind: ChainFailureKind }[];
}

/** GET /replay: the station at a moment (phase-3 plan section 4; no cash block). */
export interface Replay {
  at: string;
  station: { id: number; code: StationCode; name_en: string; name_am: string };
  shift: ShiftSummary | null;
  on_duty: { user: { id: number; name: string }; joined_at: string | null; left_at: string | null }[];
  locations: {
    location: CountPlace;
    balances: { item: { id: number; name_en: string; name_am: string; unit: string }; on_hand: string; reserved: string }[];
    last_locked: { count_id: number; chain_seq: number; payload_hash: string; locked_at: string } | null;
  }[];
  open_orders: {
    order_id: number;
    order_no: string;
    table_code: string | null;
    type: string;
    sent_at: string | null;
    owner: { id: number; name: string };
    lines: { item_id: number; qty: string; status: string }[];
  }[];
  counts: {
    count_id: number;
    shift_id: number;
    location_id: number;
    type: "opening" | "closing";
    status_at: string;
    submitted_at: string | null;
    decided_at: string | null;
    locked_at: string | null;
  }[];
}

// --- the audit log (step 4.5f over the reads of step 4.5a; phase-4 plan section 4.7) ------------------------------------------------------

/** A person as the audit log names them (Shared ActorNames): null when nobody of the branch has the id. */
export interface AuditPerson {
  id: number;
  name: string | null;
}

/** What changed, as GET /audit-log sums it up: the changed fields (sorted, at most 10, the bookkeeping fields left out), and the context's reason. */
export interface AuditSummary {
  changed: string[];
  more: number;
  reason: string | null;
}

/** A row of GET /audit-log, newest first. `at` is UTC (ISO 8601); subject_type is the morph string the API wrote (a model's class, or a short name). */
export interface AuditEntry {
  id: number;
  at: string;
  actor: AuditPerson;
  /** the person acting at a shared pad by short code (the account signed in is the actor) */
  acting_user: AuditPerson | null;
  action: string;
  subject_type: string;
  subject_id: number | null;
  station_id: number | null;
  shift_id: number | null;
  device_id: number | null;
  ip: string | null;
  summary: AuditSummary;
}

export type AuditJson = null | boolean | number | string | AuditJson[] | { [key: string]: AuditJson };

/** GET /audit-log/{id}: the row with before, after and context, redacted again on every read ("[redacted]" in place of a hidden value). */
export interface AuditDetail extends AuditEntry {
  before: Record<string, AuditJson> | null;
  after: Record<string, AuditJson> | null;
  context: Record<string, AuditJson> | null;
}

export interface AuditPage {
  data: AuditEntry[];
  next_cursor: string | null;
}
