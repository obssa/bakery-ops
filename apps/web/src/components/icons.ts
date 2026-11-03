import {
  ChartColumn,
  ChefHat,
  Clock,
  CreditCard,
  CalendarDays,
  FileText,
  Flame,
  HandPlatter,
  LayoutDashboard,
  Package,
  ReceiptText,
  Server,
  Tag,
  UsersRound,
  Wallet,
  Wine,
  type LucideIcon,
} from "lucide-react";
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
  orders: ReceiptText,
  payments: CreditCard,
  catalog: Tag,
  inventory: Package,
  planner: CalendarDays,
  people: UsersRound,
  shifts: Clock,
  analytics: ChartColumn,
  audit: FileText,
  system: Server,
};

/** The stroke of every shell icon. */
export const ICON_STROKE = 1.75;
