// The screens of the system, in the order the shell lists them. Only the admin console is live in Phase 0; the others
// say which phase brings them (their dictionaries' `coming` text).
export const SCREENS = [
  { href: "/pad", key: "pad", coming: "pad" },
  { href: "/till", key: "till", coming: "till" },
  { href: "/kds/kitchen", key: "kds_kitchen", coming: "kds" },
  { href: "/kds/bar", key: "kds_bar", coming: "kds" },
  { href: "/production", key: "production", coming: "production" },
  { href: "/admin", key: "admin", coming: null },
] as const;
