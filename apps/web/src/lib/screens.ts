// The screens of the system, in the order the shell lists them. The admin console, the waiter pad, the kitchen and bar displays and the cashier till are live; the others
// say which phase brings them (their dictionaries' `coming` text).
export const SCREENS = [
  { href: "/pad", key: "pad", coming: null },
  { href: "/till", key: "till", coming: null },
  { href: "/kds/kitchen", key: "kds_kitchen", coming: null },
  { href: "/kds/bar", key: "kds_bar", coming: null },
  { href: "/production", key: "production", coming: "production" },
  { href: "/admin", key: "admin", coming: null },
] as const;
