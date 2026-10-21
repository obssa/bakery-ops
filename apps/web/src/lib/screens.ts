// The screens of the system, in the order the shell lists them. All of them are live as of step 2.8a (the production board was
// the last one that said "available in Phase 2").
export const SCREENS = [
  { href: "/pad", key: "pad" },
  { href: "/till", key: "till" },
  { href: "/kds/kitchen", key: "kds_kitchen" },
  { href: "/kds/bar", key: "kds_bar" },
  { href: "/production", key: "production" },
  { href: "/admin", key: "admin" },
] as const;
