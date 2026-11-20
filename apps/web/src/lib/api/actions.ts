import { STATE_MACHINES } from "./stateMachines";

// What the API says may happen next (ADR 0032). Every record with a status machine carries `actions`: the moves the person looking may make
// now, one name per write endpoint, read from the API actions' own rules. The screens offer exactly those and copy none of the rules. A
// record read before the API had them (an outbox snapshot, an old cached read) has none: nothing is offered until it is read again.

export type OrderAction = "edit" | "send" | "cancel" | "void" | "refund";
export type LineAction = "accept" | "ready" | "reject" | "serve";
export type PaymentAction = "verify" | "dispute" | "void";
export type RequestAction = "assign" | "accept" | "start" | "finish" | "confirm" | "send_back" | "cancel";
export type TransferAction = "confirm" | "reject";
export type PurchaseAction = "edit" | "confirm" | "cancel";
export type ShiftAction = "join" | "leave" | "close" | "cancel";
export type CountAction = "approve" | "cosign" | "reject" | "unlock";

/** Does the API let the person looking make this move on the record now? */
export function may<A extends string>(record: { actions?: readonly A[] } | null | undefined, action: A): boolean {
  return !!record && Array.isArray(record.actions) && record.actions.includes(action);
}

type Machine = keyof typeof STATE_MACHINES;

/**
 * The statuses a record reaches only through `via`, starting from `from` (the generated mirror of the API's status enums): every status
 * reachable from `via`, and `via` itself, less those `from` reaches without passing through it. For an order, the statuses only a sent
 * order reaches: cancelled is not among them, since an open order is cancelled without being sent.
 */
export function onlyThrough(machine: Machine, from: string, via: string): string[] {
  const table = STATE_MACHINES[machine].transitions as Record<string, readonly string[]>;
  const around = new Set<string>();
  const todo = (table[from] ?? []).filter((s) => s !== via);
  while (todo.length > 0) {
    const next = todo.shift() as string;
    if (next === via || around.has(next)) continue;
    around.add(next);
    todo.push(...(table[next] ?? []));
  }
  const reachable = (STATE_MACHINES[machine].reachable as Record<string, readonly string[]>)[via] ?? [];
  return [via, ...reachable].filter((status) => !around.has(status));
}

/** The statuses of a machine that answer yes to one of its enum's questions (`isRunning`, `isSubmitted`, ...). */
export function statusesWhere(machine: Machine, question: string): readonly string[] {
  return (STATE_MACHINES[machine].is as Record<string, readonly string[]>)[question] ?? [];
}
