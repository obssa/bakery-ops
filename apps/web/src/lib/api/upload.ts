import { handleUnauthorized, useSession } from "../session";
import { ApiError, apiUrl, callHeaders, refusal } from "./client";
import type { ApiErrorBody } from "./types";

// A multipart upload that reports its progress (step 6.4b: the product photo, phase-6 plan 6.4b): `fetch` cannot say how much of a body
// has gone, so this is an XMLHttpRequest. It carries what every call carries (lib/api/client.ts): the bearer token, the Idempotency-Key
// (rule 7, ADR 0003: the same key for a retry of the same photo and crop, so the API replays its first answer), X-Device-Id and the station
// header; a refusal is the API's bilingual body as an ApiError, a network failure or a time-out is status 0, and a 401 signs this device out
// as `authed` does. Admin screens are online only, so an upload never goes through the outbox: it is sent now, or it fails and says so.

/** How long an upload may take before it counts as a network failure: a 2400 px JPEG on a slow line, then the server's three sizes. */
export const UPLOAD_TIMEOUT_MS = 120_000;

export interface UploadOptions {
  form: FormData;
  idempotencyKey: string;
  /** the share of the body sent so far, 0 to 1 (only when the browser knows the total) */
  onProgress?: (sent: number) => void;
  signal?: AbortSignal;
  timeoutMs?: number;
}

function parse(text: string): unknown {
  if (!text) return undefined;
  try {
    return JSON.parse(text);
  } catch {
    return undefined;
  }
}

/** POST `form` to `path` with the signed-in session's token; resolves with the API's JSON answer. */
export function upload<T>(path: string, { form, idempotencyKey, onProgress, signal, timeoutMs = UPLOAD_TIMEOUT_MS }: UploadOptions): Promise<T> {
  const { token } = useSession.getState();
  const { headers, device, page } = callHeaders("application/json", { "Idempotency-Key": idempotencyKey, ...(token ? { Authorization: `Bearer ${token}` } : {}) });

  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", apiUrl(path));
    // the browser writes the multipart Content-Type with its boundary
    for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value);
    xhr.timeout = timeoutMs;
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) onProgress?.(Math.min(1, event.loaded / event.total));
    };
    const network = () => reject(new ApiError(0, { message_key: "network" }));
    xhr.onerror = network;
    xhr.ontimeout = network;
    xhr.onabort = () => reject(new DOMException("The upload was cancelled.", "AbortError"));
    xhr.onload = () => {
      const json = parse(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300) {
        resolve(json as T);
        return;
      }
      const error = refusal(xhr.status, json && typeof json === "object" ? (json as Partial<ApiErrorBody>) : {}, device, page);
      handleUnauthorized(error);
      reject(error);
    };
    if (signal?.aborted) {
      reject(new DOMException("The upload was cancelled.", "AbortError"));
      return;
    }
    signal?.addEventListener("abort", () => xhr.abort(), { once: true });
    xhr.send(form);
  });
}
