import { randomUUID } from "node:crypto";
import { env } from "@/lib/env";
import { fail, guard, json, readJson } from "@/lib/http";
import { presignPut } from "@/lib/r2";
import { keys, limits, redis } from "@/lib/redis";
import { prepareSchema, type Pending, type ReceiptRecord } from "@/lib/schema";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/**
 * POST /api/v1/uploads/prepare
 * Authenticates the device, validates the upload description and returns a
 * short-lived presigned PUT to the private R2 bucket. No file body ever
 * passes through Vercel.
 */
export async function POST(req: Request): Promise<Response> {
  const device = await guard(req, limits.prepare);
  if (device instanceof Response) return device;

  let body: unknown;
  try {
    body = await readJson(req);
  } catch {
    return fail(400, "Body must be a small JSON object");
  }
  const parsed = prepareSchema.safeParse(body);
  if (!parsed.success) return fail(400, "Invalid request: " + parsed.error.issues.map((i) => i.path.join(".") + " " + i.message).join("; "));
  const input = parsed.data;

  if (input.zipBytes > env.maxZipBytes()) return fail(413, `Bundle exceeds ${env.maxZipBytes()} bytes`);
  if (input.luaBytes > env.maxLuaBytes()) return fail(413, `SavedVariables exceed ${env.maxLuaBytes()} bytes`);
  // A deflated bundle is never much larger than its content.
  if (input.zipBytes > input.luaBytes + 64 * 1024) return fail(400, "Bundle size is inconsistent with file size");

  const r = redis();
  const existing = await r.get<ReceiptRecord>(keys.receipt(device.id, input.sourceId, input.sha256));
  if (existing) return json({ status: "duplicate", receiptId: existing.receiptId });

  const uploadId = randomUUID();
  const objectKey = `incoming/${device.id}/${input.sourceId}/${input.sha256}-${uploadId}.zip`;
  const ttl = env.presignSeconds();
  const put = await presignPut(objectKey, input.zipBytes, input.zipSha256, ttl);

  const pending: Pending = { ...input, device: device.id, objectKey, createdAt: new Date().toISOString() };
  // Keep the pending record a while after the URL expires so a slow
  // upload that started in time can still complete.
  await r.set(keys.pending(uploadId), pending, { ex: ttl + 900 });

  return json({
    status: "upload",
    uploadId,
    uploadUrl: put.url,
    headers: put.headers,
    expiresAt: new Date(Date.now() + ttl * 1000).toISOString(),
  });
}
