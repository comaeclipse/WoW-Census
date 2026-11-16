export const dynamic = "force-dynamic";

export function GET(): Response {
  return Response.json({ ok: true, service: "wowcensus-upload-api" }, { headers: { "cache-control": "no-store" } });
}
