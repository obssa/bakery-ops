import { randomUUID } from "node:crypto";
import type { Api } from "./api";
import { run } from "./env";

// A print bridge in the test process (step 5.3c, e2e/p5/print.spec.ts): the admin enrols a device of kind `bridge`, which answers its
// token once (step 5.1a); the bridge then speaks the API's bridge routes (step 5.3a, phase-5 plan 4.3) with `Authorization: Bridge <token>`,
// as tools/print-bridge does (5.3b): GET /bridge/jobs/next (204, or 200 {job_id}), claim, then printed or failed. Each call is driven by
// the test, so the till can be watched in between (queued, then printing, then printed). The CI stack only: the live E2E branch must not
// collect bridges, and the bridge's polls make the branch's printer look connected for two minutes.

export interface BridgeClaim {
  job_id: number;
  order_number: string | number;
  copies: number;
  escpos_base64: string;
  expires_at: string;
}

interface BridgeDevice {
  id: number;
  name: string;
  kind: string;
  last_seen_at: string | null;
  revoked_at: string | null;
}

export class FakeBridge {
  private constructor(
    readonly id: number,
    readonly name: string,
    private readonly token: string,
    private readonly origin: string,
  ) {}

  /** Enrols a new bridge in the admin's branch (POST /devices kind bridge) and keeps the token it answers once. */
  static async enrol(admin: Api, label: string): Promise<FakeBridge> {
    const name = `E2E bridge ${label} ${run}`;
    const made = await admin.post<{ id: number; token?: string }>("/devices", {
      name,
      fingerprint: `e2e-bridge-${run}-${label}-${randomUUID().slice(0, 8)}`,
      kind: "bridge",
      client_uuid: randomUUID(),
    });
    if (!made.token) throw new Error("the bridge's enrolment answered no token");
    return new FakeBridge(made.id, name, made.token, admin.origin);
  }

  /**
   * Revokes every bridge of the branch still enrolled, so none was seen within print.bridge_seen_seconds and the till's print answers
   * 409 payments.print.no_bridge (a revoked bridge never counts). The CI stack only.
   */
  static async revokeAll(admin: Api): Promise<number> {
    const { data } = await admin.get<{ data: BridgeDevice[] }>("/devices", { kind: "bridge", revoked: 0 });
    for (const device of data) await admin.post(`/devices/${device.id}/revoke`, {});
    return data.length;
  }

  async revoke(admin: Api): Promise<void> {
    await admin.post(`/devices/${this.id}/revoke`, {});
  }

  private async call(method: "GET" | "POST", path: string, body?: unknown): Promise<{ status: number; json: unknown }> {
    const response = await fetch(`${this.origin}/api/v1/bridge${path}`, {
      method,
      headers: {
        Authorization: `Bridge ${this.token}`,
        Accept: "application/json",
        ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await response.text();
    let json: unknown = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      json = text;
    }
    return { status: response.status, json };
  }

  /** One poll: the oldest queued job of the branch, or null (204). The poll is what marks the bridge as seen. */
  async next(): Promise<number | null> {
    const { status, json } = await this.call("GET", "/jobs/next");
    if (status === 204) return null;
    if (status !== 200) throw new Error(`GET /bridge/jobs/next answered ${status}: ${JSON.stringify(json)}`);
    return (json as { job_id: number }).job_id;
  }

  /** Polls every half second until a job waits, at most `timeoutMs`. */
  async waitForJob(timeoutMs = 30_000): Promise<number> {
    const until = Date.now() + timeoutMs;
    for (;;) {
      const job = await this.next();
      if (job !== null) return job;
      if (Date.now() > until) throw new Error(`no print job reached the bridge within ${timeoutMs} ms`);
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
  }

  async claim(jobId: number, width?: 576 | 384): Promise<BridgeClaim> {
    const { status, json } = await this.call("POST", `/jobs/${jobId}/claim`, { client_uuid: randomUUID(), ...(width ? { width } : {}) });
    if (status !== 200) throw new Error(`claim of job ${jobId} answered ${status}: ${JSON.stringify(json)}`);
    return json as BridgeClaim;
  }

  async printed(jobId: number): Promise<void> {
    const { status, json } = await this.call("POST", `/jobs/${jobId}/printed`, { client_uuid: randomUUID() });
    if (status !== 204) throw new Error(`printed of job ${jobId} answered ${status}: ${JSON.stringify(json)}`);
  }

  async failed(jobId: number, failureKey: "paper_out" | "offline" | "cover_open" | "unknown"): Promise<void> {
    const { status, json } = await this.call("POST", `/jobs/${jobId}/failed`, { failure_key: failureKey, client_uuid: randomUUID() });
    if (status !== 204) throw new Error(`failed of job ${jobId} answered ${status}: ${JSON.stringify(json)}`);
  }
}

/** The receipt's bytes from a claim. */
export function claimBytes(claim: BridgeClaim): Uint8Array {
  return new Uint8Array(Buffer.from(claim.escpos_base64, "base64"));
}
