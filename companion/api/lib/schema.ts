import { z } from "zod";

const hex64 = z.string().regex(/^[0-9a-f]{64}$/, "must be 64 lowercase hex chars");

export const prepareSchema = z.object({
  sourceId: hex64,
  sha256: hex64,
  luaBytes: z.number().int().positive(),
  zipBytes: z.number().int().positive(),
  zipSha256: hex64,
  edition: z.string().regex(/^[a-z0-9_]{1,40}$/),
  appVersion: z.string().max(40).regex(/^[\w.+-]*$/),
});
export type PrepareInput = z.infer<typeof prepareSchema>;

export const completeSchema = z.object({
  uploadId: z.string().uuid(),
});

/** Pending upload stored in Redis between prepare and complete. */
export type Pending = PrepareInput & {
  device: string;
  objectKey: string;
  createdAt: string;
};

export type ReceiptRecord = {
  receiptId: string;
  jobId: string;
  objectKey: string;
  status: "queued";
  queuedAt: string;
};
