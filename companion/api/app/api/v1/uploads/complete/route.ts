import { randomUUID } from "node:crypto";
import { env } from "@/lib/env";
import { fail, guard, json, readJson } from "@/lib/http";
import { deleteObject, headObject } from "@/lib/r2";
import { keys, limits, redis } from "@/lib/redis";
import { completeSchema, type Pending, type ReceiptRecord } from "@/lib/schema";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/**
 * POST /api/v1/uploads/complete
 * Verifies the object really landed in R2 with the promised size and type,
 * then appends a durable job to the ingest Redis Stream.
 *
 * The response status is "queued": stored and waiting for the ingest
 * worker. It is NOT an import acknowledgement and must never be used to
 * authorise pruning of a player's SavedVariables.
 */
export async function POST(req: Request): Promise<Response> {
  const device = await guard(req, limits.complete);
  if (device instanceof Response) return device;

  let body: unknown;
  try {
    body = await readJson(req, 1024);
  } catch {
    return fail(400, "Body must be a small JSON object");
  }
  const parsed = completeSchema.safeParse(body);
  if (!parsed.success) return fail(400, "Invalid uploadId");
  const { uploadId } = parsed.data;
  const r = redis();

  // Idempotent: a retried complete returns the original receipt.
  const done = await r.get<ReceiptRecord | "locked">(keys.completed(uploadId));
  if (done && done !== "locked") return json({ status: "queued", receiptId: done.receiptId, jobId: done.jobId });
  if (done === "locked") return fail(409, "Completion already in progress; retry shortly", { "retry-after": "5" });

  const pending = await r.get<Pending>(keys.pending(uploadId));
  if (!pending || pending.device !== device.id) return fail(404, "Unknown or expired upload");

  const head = await headObject(pending.objectKey);
  if (!head) return fail(409, "Object not found in storage; upload it before calling complete");
  if (head.size !== pending.zipBytes || head.contentType !== "application/zip") {
    await deleteObject(pending.objectKey).catch(() => {});
    await r.del(keys.pending(uploadId));
    return fail(422, "Stored object does not match the prepared upload");
  }

  // Short lock so concurrent completes cannot enqueue twice.
  const locked = await r.set(keys.completed(uploadId), "locked", { nx: true, ex: 60 });
  if (locked !== "OK") return fail(409, "Completion already in progress; retry shortly", { "retry-after": "5" });

  try {
    const receiptId = randomUUID();
    const queuedAt = new Date().toISOString();
    const jobId = await r.xadd(env.ingestStream(), "*", {
      receiptId,
      uploadId,
      device: pending.device,
      sourceId: pending.sourceId,
      sha256: pending.sha256,
      zipSha256: pending.zipSha256,
      objectKey: pending.objectKey,
      zipBytes: String(pending.zipBytes),
      luaBytes: String(pending.luaBytes),
      edition: pending.edition,
      appVersion: pending.appVersion,
      queuedAt,
      // Unique ingestion key the worker must use for idempotency.
      ingestKey: `${pending.device}:${pending.sourceId}:${pending.sha256}`,
    });
    const receipt: ReceiptRecord = { receiptId, jobId, objectKey: pending.objectKey, status: "queued", queuedAt };
    const ttl = env.receiptTtlSeconds();
    await Promise.all([
      r.set(keys.completed(uploadId), receipt, { ex: ttl }),
      r.set(keys.receipt(pending.device, pending.sourceId, pending.sha256), receipt, { ex: ttl }),
      r.del(keys.pending(uploadId)),
    ]);
    return json({ status: "queued", receiptId, jobId });
  } catch (err) {
    await r.del(keys.completed(uploadId));
    throw err;
  }
}
