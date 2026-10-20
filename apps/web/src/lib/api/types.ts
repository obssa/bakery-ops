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
  version: number;
}

export interface OrderTotals {
  subtotal: DecimalString;
  discount: DecimalString;
  tax: DecimalString;
  total: DecimalString;
  paid: DecimalString;
  balance: DecimalString;
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

/** GET /payment-channels */
export interface PaymentChannel {
  id: number;
  code: string;
  name_en: string;
  name_am: string;
  is_enabled: boolean;
  requires_photo: boolean;
  requires_reference: boolean;
  sort: number;
  accepted_now: boolean;
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
