import { authenticate, type Device } from "./auth.ts";
import { limits } from "./redis.ts";

export function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json", "cache-control": "no-store", ...headers },
  });
}

export const fail = (status: number, error: string, headers?: Record<string, string>) => json({ error }, status, headers);

function clientIp(req: Request): string {
  return req.headers.get("x-real-ip") ?? req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? "unknown";
}

function retryAfter(reset: number): Record<string, string> {
  return { "retry-after": String(Math.max(1, Math.ceil((reset - Date.now()) / 1000))) };
}

/** IP rate limit, then auth, then the per-device limit for this route. */
export async function guard(
  req: Request,
  deviceLimit: (id: string) => Promise<{ success: boolean; reset: number }>,
): Promise<Device | Response> {
  const ip = await limits.ip(clientIp(req));
  if (!ip.success) return fail(429, "Too many requests", retryAfter(ip.reset));
  const device = authenticate(req);
  if (!device) return fail(401, "Invalid device token");
  const dl = await deviceLimit(device.id);
  if (!dl.success) return fail(429, "Upload rate limit reached", retryAfter(dl.reset));
  return device;
}

export async function readJson(req: Request, maxBytes = 8192): Promise<unknown> {
  const len = Number(req.headers.get("content-length") ?? 0);
  if (len > maxBytes) throw new Error("body too large");
  const text = await req.text();
  if (text.length > maxBytes) throw new Error("body too large");
  return JSON.parse(text);
}
