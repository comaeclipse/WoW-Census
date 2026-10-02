// Cloudflare Pages advanced-mode Function. Only content-page routes invoke it.
// tools/build-markdown-pages.js copies this file to pages/_worker.js, so imports
// resolve from pages/; wrangler bundles them on deploy.
//
// A content page is served from KV when tools/publish-pages.js has published it
// with the layout this deploy carries -- fresh data without a redeploy -- and
// from the deploy's static copy otherwise.
import LAYOUT from "./_layout.json";
import { PAGES, sourceForPath, pagePath } from "../site/src/edition.mjs";

const editions = new Set(['tbc', 'classic', 'sod', 'mop', 'retail']);
const pages = new Set(PAGES);
const HTML_CACHE = 'public, max-age=300, stale-while-revalidate=86400';

// "/tbc/auctionhouse" -> { source: "classic-progression", page: "auctionhouse", edition: "tbc" }
function contentPage(pathname) {
  const parts = pathname.split('/').filter(Boolean);
  const edition = editions.has(parts[0]) ? parts.shift() : '';
  if (parts.length > 1) return null;
  const page = (parts[0] || 'index').replace(/\.html$/, '');
  if (!pages.has(page)) return null;
  return { source: sourceForPath(edition ? `/${edition}/` : '/'), page, edition };
}

function acceptsMarkdown(value) {
  return value.split(',').some((entry) => {
    const [type, ...params] = entry.trim().split(';');
    return type.trim().toLowerCase() === 'text/markdown' &&
      !params.some((param) => /^q\s*=\s*0(?:\.0*)?$/i.test(param.trim()));
  });
}

function withHeaders(response, set) {
  const headers = new Headers(response.headers);
  headers.set('Vary', [...new Set([...(headers.get('Vary') || '').split(',').map((v) => v.trim()).filter(Boolean), 'Accept'])].join(', '));
  for (const [name, value] of Object.entries(set)) headers.set(name, value);
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

// The published copy of a page ("page") or its Markdown ("md"), or null when it
// is unpublished or was rendered with a different layout than this deploy's.
// Only canonical paths are answered here, so Pages still redirects "/tbc" and
// "/tbc/auctionhouse.html" to their canonical form.
async function published(env, kind, hit, pathname) {
  if (!env.PAGES_KV || !hit.source || pathname !== pagePath(hit.source, hit.page)) return null;
  const { value, metadata } = await env.PAGES_KV.getWithMetadata(`${kind}:${hit.source}:${hit.page}`, { type: 'text', cacheTtl: 300 });
  return value && metadata && metadata.layout === LAYOUT.id ? value : null;
}

export default {
  async fetch(request, env) {
    const { pathname } = new URL(request.url);
    const hit = contentPage(pathname);
    if (!hit || !['GET', 'HEAD'].includes(request.method)) return env.ASSETS.fetch(request);
    const markdown = acceptsMarkdown(request.headers.get('Accept') || '');
    const contentType = markdown ? 'text/markdown; charset=utf-8' : 'text/html; charset=utf-8';

    const body = await published(env, markdown ? 'md' : 'page', hit, pathname);
    if (body !== null) return withHeaders(new Response(body), {
      'Content-Type': contentType, 'Cache-Control': HTML_CACHE, 'X-Page-Source': 'kv',
    });

    if (!markdown) {
      const response = await env.ASSETS.fetch(request);
      return withHeaders(response, response.ok ? { 'Cache-Control': HTML_CACHE, 'X-Page-Source': 'static' } : {});
    }
    const assetUrl = new URL(request.url);
    assetUrl.pathname = `${hit.edition ? `/${hit.edition}` : ''}/${hit.page}.md`;
    const response = await env.ASSETS.fetch(new Request(assetUrl, request));
    if (!response.ok) return response;
    return withHeaders(response, { 'Content-Type': contentType, 'Cache-Control': HTML_CACHE, 'X-Page-Source': 'static' });
  },
};
