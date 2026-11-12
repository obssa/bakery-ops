"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { authed, can, useSession } from "../session";

// TEMPORARY (ADR 0025): GET and PUT /brand/demo-login, the demo branch's switch of the sign-in page's one-click demo buttons (the admin's,
// branding.manage). The switch shows its new position at once and goes back if the server refuses it; the answer replaces what the page
// holds.

/** on: the saved position; available: this admin's branch is the demo branch; forced_off: the server's BAKERY_DEMO_LOGIN=false. */
export interface DemoLoginSwitch {
  on: boolean;
  available: boolean;
  forced_off: boolean;
}

const KEY = ["demo-login-switch"] as const;

export function useDemoLoginSwitch() {
  const enabled = useSession((s) => s.status === "signed-in" && can(s.me, "branding.manage"));
  return useQuery({
    queryKey: KEY,
    queryFn: async () => (await authed<{ data: DemoLoginSwitch }>("/brand/demo-login")).data,
    enabled,
  });
}

export function useSaveDemoLoginSwitch() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ on, idempotencyKey }: { on: boolean; idempotencyKey: string }) =>
      authed<{ data: DemoLoginSwitch }>("/brand/demo-login", { method: "PUT", body: { on }, idempotencyKey }),
    onMutate: async ({ on }) => {
      await client.cancelQueries({ queryKey: KEY });
      const before = client.getQueryData<DemoLoginSwitch>(KEY);
      if (before) client.setQueryData<DemoLoginSwitch>(KEY, { ...before, on });
      return { before };
    },
    onError: (_error, _input, context) => {
      if (context?.before) client.setQueryData(KEY, context.before);
    },
    onSuccess: (answer) => client.setQueryData(KEY, answer.data),
  });
}
