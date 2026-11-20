import type { Transfer } from "./api/inventory";
import type { NewTransfer } from "./api/types";
import { compareDecimal } from "./decimal";

// The request sheet's check of the API's answer (ADR 0030). POST /transfers answers a client_uuid it has seen with the transfer written
// then (200), whatever the new body asks. The sheet keeps its client_uuid across a send whose outcome is unknown (no answer, a 5xx), so a
// resend cannot ask twice; when the person changed the request before resending, the answer is the first request, not the edited one, and
// the sheet must say so instead of showing the edit as sent.

/** Whether the transfer the API answered with is the one this body asks for (the item, the places and the quantity). */
export function answeredAsSent(sent: NewTransfer, answer: Pick<Transfer, "item" | "to_location" | "from_location" | "qty">): boolean {
  if (answer.item.id !== sent.item_id || answer.to_location.id !== sent.to_location_id) return false;
  if (sent.from_location_id !== undefined && answer.from_location.id !== sent.from_location_id) return false;
  return compareDecimal(answer.qty, sent.qty) === 0;
}
