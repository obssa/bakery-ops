import { randomUUID } from "node:crypto";
import { rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

// Runs once per Playwright run, before the first worker. It names the file the API client keeps the run's logins in (see api.ts): a
// worker restarted by a failed test finds its accounts already logged in instead of spending the server's ten logins a minute again.
// The file lives in the system temp folder, not in test-results, so a failed run never uploads session tokens as an artifact.
export default function globalSetup(): () => void {
  const file = join(tmpdir(), `bakery-e2e-sessions-${randomUUID()}.json`);
  process.env.E2E_TOKEN_FILE = file;
  return () => rmSync(file, { force: true });
}
