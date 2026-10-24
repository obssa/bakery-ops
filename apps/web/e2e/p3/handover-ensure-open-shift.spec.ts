import { expect, test } from "@playwright/test";
import { createServer, type Server } from "node:http";
import type { AddressInfo } from "node:net";
import { Api } from "../support/api";
import { ensureOpenShift } from "../support/shifts";

// ensureOpenShift must leave the station's shift `open` whatever state an earlier run left it in. The live E2E kitchen was found in its
// closing count (`counting`), and with the shift rules on every stock movement there was refused (409 inventory.shift.required). A tiny
// local API plays the shift of one station through its states; no browser and no real API are involved. It rides in the p3-handover
// project (the name matches).

type Status = "none" | "opening" | "open" | "counting" | "stuck";

interface Fake {
  server: Server;
  origin: string;
  state: { status: Status; calls: string[]; pending: number };
}

async function fakeApi(initial: Status): Promise<Fake> {
  const state = { status: initial, calls: [] as string[], pending: initial === "counting" ? 2 : initial === "opening" ? 1 : 0 };
  const server = createServer((request, response) => {
    let body = "";
    request.on("data", (chunk) => (body += chunk));
    request.on("end", () => {
      response.setHeader("Content-Type", "application/json");
      const url = new URL(request.url ?? "/", "http://x");
      const path = url.pathname.replace("/api/v1", "");
      state.calls.push(`${request.method} ${path}`);
      if (path === "/login") {
        response.end(JSON.stringify({ token: "t", user: { id: 1, branch_id: 1, name: "x", phone: "p", stations: [] }, permissions: [] }));
      } else if (path === "/stations/7/shifts/current") {
        const running = state.status === "opening" || state.status === "open" || state.status === "counting" || state.status === "stuck";
        response.end(JSON.stringify({ data: running ? { id: 70, status: state.status === "stuck" ? "counting" : state.status, started_by: null, roster: [] } : null }));
      } else if (path === "/stock-counts" && request.method === "GET") {
        response.end(JSON.stringify({ data: [] }));
      } else if (path === "/shifts/70/close") {
        state.status = "counting";
        response.end(JSON.stringify({}));
      } else if (path === "/shifts/70/counts") {
        response.end(JSON.stringify({ data: Array.from({ length: state.pending }, (_, i) => ({ id: 700 + i, status: "draft", cash: null })) }));
      } else if (/^\/stock-counts\/\d+\/lines$/.test(path)) {
        response.end(JSON.stringify({}));
      } else if (/^\/stock-counts\/\d+\/submit$/.test(path)) {
        if (state.status !== "stuck") {
          state.pending -= 1;
          if (state.pending <= 0) state.status = initial === "opening" ? "open" : "none";
        }
        response.end(JSON.stringify({}));
      } else if (path === "/stations/7/shifts/start") {
        state.status = "open";
        response.statusCode = 201;
        response.end(JSON.stringify({ shift: { id: 71 } }));
      } else {
        response.statusCode = 404;
        response.end(JSON.stringify({ message_key: "not.found" }));
      }
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  return { server, origin: `http://127.0.0.1:${(server.address() as AddressInfo).port}`, state };
}

async function run(initial: Status): Promise<{ fake: Fake; error: Error | null }> {
  const fake = await fakeApi(initial);
  const { api } = await Api.login("+2519000003" + initial.length, "pw", 1, fake.origin);
  try {
    await ensureOpenShift(api, { id: 7 });
    return { fake, error: null };
  } catch (error) {
    return { fake, error: error as Error };
  } finally {
    fake.server.close();
  }
}

test.describe("p3 e2e support: ensureOpenShift", () => {
  test("no shift: starts one carried", async () => {
    const { fake, error } = await run("none");
    expect(error).toBeNull();
    expect(fake.state.status).toBe("open");
    expect(fake.state.calls).toContain("POST /stations/7/shifts/start");
  });

  test("an opening shift: finishes the opening counts and starts nothing", async () => {
    const { fake, error } = await run("opening");
    expect(error).toBeNull();
    expect(fake.state.status).toBe("open");
    expect(fake.state.calls.some((call) => call.endsWith("/submit"))).toBe(true);
    expect(fake.state.calls).not.toContain("POST /stations/7/shifts/start");
  });

  test("a shift in its closing count: finishes the closing counts, then starts a carried one", async () => {
    const { fake, error } = await run("counting");
    expect(error).toBeNull();
    expect(fake.state.status).toBe("open");
    const submits = fake.state.calls.filter((call) => call.endsWith("/submit")).length;
    expect(submits).toBe(2);
    expect(fake.state.calls.indexOf("POST /stations/7/shifts/start")).toBeGreaterThan(fake.state.calls.lastIndexOf("POST /stock-counts/701/submit"));
  });

  test("an open shift: nothing is written", async () => {
    const { fake, error } = await run("open");
    expect(error).toBeNull();
    expect(fake.state.calls.filter((call) => call.startsWith("POST") && call !== "POST /login")).toEqual([]);
  });

  test("a station that is still not open at the end is an error, not a fixture that carries on", async () => {
    const { error } = await run("stuck");
    expect(error?.message).toContain("not open");
  });
});
