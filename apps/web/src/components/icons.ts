import {
  BriefcaseBusiness,
  CakeSlice,
  ChartColumn,
  ChefHat,
  Clock,
  Coffee,
  CreditCard,
  CalendarDays,
  Croissant,
  FileText,
  Flame,
  HandPlatter,
  LayoutDashboard,
  Package,
  ReceiptText,
  Server,
  ShieldCheck,
  Tag,
  UsersRound,
  UtensilsCrossed,
  Wallet,
  Wine,
  type LucideIcon,
} from "lucide-react";
import type { Role } from "@/lib/api/types";
import type { ScreenNavKey } from "@/lib/screens";

// The line icons of the shell (fresh-counter.md 2: lucide-react, 1.75-2 px stroke, 18-22 px), in a plain module so a server page (the
// start page) draws them as well as the client frames. Each one sits beside its label and is hidden from readers, so no name changes.

/** The screens of the "Screens" navigation and the start page. */
export const SCREEN_ICONS: Record<ScreenNavKey, LucideIcon> = {
  pad: HandPlatter,
  till: Wallet,
  kds_kitchen: ChefHat,
  kds_bar: Wine,
  production: Flame,
  admin: LayoutDashboard,
};

/** The admin console's sections (AdminFrame's sidebar). */
export const SECTION_ICONS: Record<string, LucideIcon> = {
  today: LayoutDashboard,
  orders: ReceiptText,
  payments: CreditCard,
  tables: UtensilsCrossed,
  catalog: Tag,
  inventory: Package,
  planner: CalendarDays,
  people: UsersRound,
  shifts: Clock,
  analytics: ChartColumn,
  audit: FileText,
  system: Server,
};

/** A role (the sign-in page's demo buttons, TEMPORARY, ADR 0025). */
export const ROLE_ICONS: Record<Role, LucideIcon> = {
  admin: ShieldCheck,
  manager: BriefcaseBusiness,
  cashier: Wallet,
  waiter: HandPlatter,
  chef: ChefHat,
  barista: Coffee,
  cake_baker: CakeSlice,
  bread_baker: Croissant,
};

/** The stroke of every shell icon. */
export const ICON_STROKE = 1.75;
