import { Redis } from "@upstash/redis";
import { Ratelimit } from "@upstash/ratelimit";

let client: Redis | null = null;

/** Upstash Redis over REST (UPSTASH_REDIS_REST_URL / _TOKEN). */
export function redis(): Redis {
  client ??= Redis.fromEnv();
  return client;
}

const limiters = new Map<string, Ratelimit>();

function limiter(name: string, tokens: number, window: `${number} ${"s" | "m" | "h"}`): Ratelimit {
  let l = limiters.get(name);
  if (!l) {
    l = new Ratelimit({ redis: redis(), limiter: Ratelimit.slidingWindow(tokens, window), prefix: `wowcensus:rl:${name}` });
    limiters.set(name, l);
  }
  return l;
}

// Per-IP limits run before auth (blunts token guessing); per-device after.
export const limits = {
  ip: (ip: string) => limiter("ip", 60, "10 m").limit(ip),
  prepare: (device: string) => limiter("prepare", 30, "1 h").limit(device),
  complete: (device: string) => limiter("complete", 60, "1 h").limit(device),
};

export const keys = {
  pending: (uploadId: string) => `wowcensus:pending:${uploadId}`,
  completed: (uploadId: string) => `wowcensus:completed:${uploadId}`,
  receipt: (device: string, sourceId: string, sha: string) => `wowcensus:receipt:${device}:${sourceId}:${sha}`,
};
