"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { applyChanges, type RuleChange } from "../countingRules";
import { authed, can, useSession } from "../session";
import type { CountingRulesView } from "./types";

// GET and PUT /counting-rules (ADR 0025): the admin's alone (shifts.manage). A switch shows its new position at once and goes back if the
// server refuses it; the answer is the whole view, which replaces what the page holds.

const KEY = ["counting-rules"] as const;

export function useCountingRules() {
  const enabled = useSession((s) => s.status === "signed-in" && can(s.me, "shifts.manage"));
  return useQuery({
    queryKey: KEY,
    queryFn: async () => (await authed<{ data: CountingRulesView }>("/counting-rules")).data,
    enabled,
    refetchInterval: 60_000,
  });
}

export function useSaveCountingRules() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ changes, idempotencyKey }: { changes: RuleChange[]; idempotencyKey: string }) =>
      authed<{ data: CountingRulesView }>("/counting-rules", { method: "PUT", body: { stations: changes }, idempotencyKey }),
    onMutate: async ({ changes }) => {
      await client.cancelQueries({ queryKey: KEY });
      const before = client.getQueryData<CountingRulesView>(KEY);
      if (before) client.setQueryData<CountingRulesView>(KEY, applyChanges(before, changes));
      return { before };
    },
    onError: (_error, _input, context) => {
      if (context?.before) client.setQueryData(KEY, context.before);
    },
    onSuccess: (answer) => {
      client.setQueryData(KEY, answer.data);
      // the station screens read the opening rule from /me
      void client.invalidateQueries({ queryKey: ["me-work-stations"] });
    },
  });
}
