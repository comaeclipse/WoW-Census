import { test } from "node:test";
import assert from "node:assert/strict";
import { authenticate, sha256Hex } from "../lib/auth.ts";
import { prepareSchema } from "../lib/schema.ts";

const token = "pilot-token-0123456789abcdef";
const digest = sha256Hex(token);
const req = (auth?: string) => new Request("https://x/api", { headers: auth ? { authorization: auth } : {} });

test("accepts the configured token", () => {
  const d = authenticate(req("Bearer " + token), digest);
  assert.ok(d);
  assert.match(d.id, /^dev_[0-9a-f]{20}$/);
  assert.ok(!d.id.includes(digest.slice(0, 20)), "device id must not expose the digest");
});

test("rejects wrong, missing and malformed tokens", () => {
  assert.equal(authenticate(req("Bearer wrong-token-0123456789"), digest), null);
  assert.equal(authenticate(req(), digest), null);
  assert.equal(authenticate(req("Basic " + token), digest), null);
  assert.equal(authenticate(req("Bearer " + token), "not-hex"), null);
});

test("prepare schema", () => {
  const h = "a".repeat(64);
  const ok = { sourceId: h, sha256: h, zipSha256: h, luaBytes: 10, zipBytes: 5, edition: "classic_era", appVersion: "0.1.0-dev" };
  assert.ok(prepareSchema.safeParse(ok).success);
  assert.ok(!prepareSchema.safeParse({ ...ok, sourceId: "C:/Users/x" }).success);
  assert.ok(!prepareSchema.safeParse({ ...ok, edition: "../../etc" }).success);
  assert.ok(!prepareSchema.safeParse({ ...ok, zipBytes: -1 }).success);
});
