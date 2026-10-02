// Cloudflare Pages advanced-mode Function. Only content-page routes invoke it.
const editions = new Set(['tbc', 'classic', 'sod', 'mop', 'retail']);
const pages = new Set(['index', 'combos', 'auctionhouse', 'guilds', 'geography', 'talents']);

function markdownPath(pathname) {
  const parts = pathname.split('/').filter(Boolean);
  const edition = editions.has(parts[0]) ? parts.shift() : '';
  if (parts.length > 1) return null;
  const page = (parts[0] || 'index').replace(/\.html$/, '');
  if (!pages.has(page)) return null;
  return `${edition ? `/${edition}` : ''}/${page}.md`;
}

function acceptsMarkdown(value) {
  return value.split(',').some((entry) => {
    const [type, ...params] = entry.trim().split(';');
    return type.trim().toLowerCase() === 'text/markdown' &&
      !params.some((param) => /^q\s*=\s*0(?:\.0*)?$/i.test(param.trim()));
  });
}

export default {
  async fetch(request, env) {
    const pathname = new URL(request.url).pathname;
    const target = markdownPath(pathname);
    const wantsMarkdown = target && ['GET', 'HEAD'].includes(request.method) &&
      acceptsMarkdown(request.headers.get('Accept') || '');
    if (!wantsMarkdown) {
      const response = await env.ASSETS.fetch(request);
      if (!target) return response;
      const headers = new Headers(response.headers);
      headers.set('Vary', [...new Set([...(headers.get('Vary') || '').split(',').map((v) => v.trim()).filter(Boolean), 'Accept'])].join(', '));
      if (response.ok) headers.set('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
      return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
    }
    const assetUrl = new URL(request.url);
    assetUrl.pathname = target;
    const response = await env.ASSETS.fetch(new Request(assetUrl, request));
    if (!response.ok) return response;
    const headers = new Headers(response.headers);
    headers.set('Content-Type', 'text/markdown; charset=utf-8');
    headers.set('Vary', [...new Set([...(headers.get('Vary') || '').split(',').map((v) => v.trim()).filter(Boolean), 'Accept'])].join(', '));
    headers.set('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
  },
};
