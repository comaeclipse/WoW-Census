import { createHash, timingSafeEqual } from "node:crypto";
import { env } from "./env.ts";

export type Device = { id: string };

export function sha256Hex(s: string): string {
  return createHash("sha256").update(s, "utf8").digest("hex");
}

/**
 * Pilot auth: one shared device token whose SHA-256 is configured in
 * DEMO_DEVICE_TOKEN_SHA256. The plaintext token never lives on the server.
 *
 * Before public launch replace this with per-device enrollment: hashed,
 * scoped, revocable tokens looked up by prefix (see README).
 */
export function authenticate(req: Request, expectedHex = env.demoTokenSha256()): Device | null {
  const header = req.headers.get("authorization") ?? "";
  const m = /^Bearer ([A-Za-z0-9._~+/=-]{16,512})$/.exec(header);
  if (!m) return null;
  const got = Buffer.from(sha256Hex(m[1]), "hex");
  const want = Buffer.from(expectedHex, "hex");
  if (want.length !== 32 || !timingSafeEqual(got, want)) return null;
  // A stable, non-secret identifier derived from the digest.
  return { id: "dev_" + sha256Hex("device:" + expectedHex).slice(0, 20) };
}
