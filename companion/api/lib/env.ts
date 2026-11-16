// Server-only configuration. Read lazily so `next build` works without secrets.

function required(name: string): string {
  const v = process.env[name];
  if (!v) throw new Error(`Missing environment variable ${name}`);
  return v;
}

function int(name: string, fallback: number): number {
  const v = process.env[name];
  const n = v ? Number.parseInt(v, 10) : NaN;
  return Number.isFinite(n) && n > 0 ? n : fallback;
}

export const env = {
  r2: () => ({
    accountId: required("R2_ACCOUNT_ID"),
    accessKeyId: required("R2_ACCESS_KEY_ID"),
    secretAccessKey: required("R2_SECRET_ACCESS_KEY"),
    bucket: required("R2_BUCKET"),
    // Sign the zip's SHA-256 into the PUT so R2 rejects altered bodies.
    signChecksum: process.env.R2_SIGN_CHECKSUM !== "false",
  }),
  // SHA-256 hex digest of the single pilot device token.
  demoTokenSha256: () => required("DEMO_DEVICE_TOKEN_SHA256").trim().toLowerCase(),
  presignSeconds: () => Math.min(int("PRESIGN_TTL_SECONDS", 120), 900),
  maxZipBytes: () => int("MAX_ZIP_BYTES", 64 * 1024 * 1024),
  maxLuaBytes: () => int("MAX_LUA_BYTES", 256 * 1024 * 1024),
  ingestStream: () => process.env.INGEST_STREAM || "wowcensus:ingest",
  receiptTtlSeconds: () => int("RECEIPT_TTL_SECONDS", 90 * 24 * 3600),
};
