import { DeleteObjectCommand, HeadObjectCommand, PutObjectCommand, S3Client } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";
import { env } from "./env.ts";

let client: S3Client | null = null;

function s3(): S3Client {
  if (!client) {
    const c = env.r2();
    client = new S3Client({
      region: "auto",
      endpoint: `https://${c.accountId}.r2.cloudflarestorage.com`,
      credentials: { accessKeyId: c.accessKeyId, secretAccessKey: c.secretAccessKey },
      // R2 does not support the SDK's default CRC32 "flexible checksums"
      // on presigned URLs; only send checksums we ask for explicitly.
      requestChecksumCalculation: "WHEN_REQUIRED",
      responseChecksumValidation: "WHEN_REQUIRED",
    });
  }
  return client;
}

export type PresignedPut = { url: string; headers: Record<string, string> };

/**
 * Presigns a PUT for a private bucket. Content-Type and Content-Length are
 * signed, so R2 refuses a body of a different size; optionally the SHA-256
 * checksum is signed too.
 */
export async function presignPut(key: string, bytes: number, zipSha256Hex: string, ttlSeconds: number): Promise<PresignedPut> {
  const c = env.r2();
  const checksum = Buffer.from(zipSha256Hex, "hex").toString("base64");
  const cmd = new PutObjectCommand({
    Bucket: c.bucket,
    Key: key,
    ContentType: "application/zip",
    ContentLength: bytes,
    ...(c.signChecksum ? { ChecksumSHA256: checksum } : {}),
  });
  const url = await getSignedUrl(s3(), cmd, {
    expiresIn: ttlSeconds,
    signableHeaders: new Set(["content-type", "content-length"]),
    unhoistableHeaders: new Set(["x-amz-checksum-sha256"]),
  });
  const headers: Record<string, string> = { "Content-Type": "application/zip" };
  if (c.signChecksum) headers["x-amz-checksum-sha256"] = checksum;
  return { url, headers };
}

export async function headObject(key: string): Promise<{ size: number; contentType?: string } | null> {
  try {
    const r = await s3().send(new HeadObjectCommand({ Bucket: env.r2().bucket, Key: key }));
    return { size: Number(r.ContentLength ?? -1), contentType: r.ContentType };
  } catch (err: unknown) {
    const status = (err as { $metadata?: { httpStatusCode?: number } }).$metadata?.httpStatusCode;
    if (status === 404) return null;
    throw err;
  }
}

export async function deleteObject(key: string): Promise<void> {
  await s3().send(new DeleteObjectCommand({ Bucket: env.r2().bucket, Key: key }));
}
