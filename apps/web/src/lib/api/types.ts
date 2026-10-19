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
  stations: { station_id: number; can_count: boolean; is_overseer: boolean }[];
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
