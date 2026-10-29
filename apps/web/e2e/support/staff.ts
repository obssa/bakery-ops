// The fixed e2e accounts, one per role under a fixed phone number (fixture.ts finds or creates them in the "E2E Test Branch"; the live
// branch keeps them across runs). Shared by fixture.ts and devices.ts, which gives each role its own enrolled device (step 5.1b).
export const STAFF = {
  chef: { phone: "+251911000201", name: "E2E Chef" },
  barista: { phone: "+251911000202", name: "E2E Barista" },
  waiter: { phone: "+251911000203", name: "E2E Waiter" },
  cashier: { phone: "+251911000204", name: "E2E Cashier" },
  manager: { phone: "+251911000205", name: "E2E Manager" },
  // Phase 2 (step 2.8a): the bakers of the production board
  cake_baker: { phone: "+251911000206", name: "E2E Cake Baker" },
  bread_baker: { phone: "+251911000207", name: "E2E Bread Baker" },
} as const;

export type StaffRole = keyof typeof STAFF;
