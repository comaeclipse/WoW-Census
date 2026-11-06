// Homepage notice shared by the local static renderer.
export const SITE_NOTICE_ID = "2026-10-home-rotation";

export const KOFI_ICON = '<svg class="notice-pixel-icon" viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><path fill="#67c9ed" d="M2 3h23v3H2zM0 6h28v12H0zM3 18h22v3H3zM7 21h15v2H7z"/><path fill="#f4f5f7" d="M4 5h19v12H4zM7 18h14v2H7z"/><path fill="#67c9ed" d="M28 8h3v8h-3zM25 16h5v3h-5z"/><path fill="#ee5a73" d="M10 8h3v2h-3zM16 8h3v2h-3zM9 10h11v3H9zM11 13h7v2h-7zM13 15h3v2h-3z"/></svg>';
export const COFFEE_ICON = '<svg class="notice-pixel-icon" viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><path fill="#ffdd00" d="M2 0h26v3H2zM0 3h30v18H0zM3 21h25v3H3z"/><path fill="#151414" d="M8 5h3v4H8zM14 4h3v5h-3zM5 10h20v3H5zM5 13h3v6H5zM20 13h3v6h-3zM8 19h15v2H8zM25 13h4v3h-4zM23 16h4v3h-4z"/><path fill="#fff" d="M8 13h12v5H8z"/></svg>';

export function siteNoticeHtml() {
  return `<script>document.documentElement.dataset.noticeChoice=String(Math.floor(Math.random()*3))</script>
  <aside class="site-notice" data-notice-id="${SITE_NOTICE_ID}" aria-label="Site notice">
    <p class="notice-text" data-notice-option="0"><img class="notice-icon" src="/notice-discord.png" alt="" width="29" height="22">Looking for contributors! Join our Discord for more info: <a href="https://discord.gg/Dzw8H3QZX" target="_blank" rel="noopener">discord.gg/Dzw8H3QZX <span class="blink">&#9656;</span></a></p>
    <p class="notice-text" data-notice-option="1">${KOFI_ICON} Like this project? Help support us on <a href="https://ko-fi.com/wowcensus" target="_blank" rel="noopener noreferrer">Ko-fi <span class="blink">&#9656;</span></a></p>
    <p class="notice-text" data-notice-option="2">${COFFEE_ICON} Like this project? Help support us on <a href="https://buymeacoffee.com/wowcensus" target="_blank" rel="noopener noreferrer">Buy Me a Coffee <span class="blink">&#9656;</span></a></p>
    <button class="notice-close" type="button" aria-label="Dismiss notice">&times;</button>
  </aside>
  <script>(function(){var n=document.currentScript.previousElementSibling,k="notice-dismissed:"+n.dataset.noticeId;try{if(localStorage.getItem(k))n.hidden=true}catch(e){}n.querySelector(".notice-close").addEventListener("click",function(){n.hidden=true;try{localStorage.setItem(k,"1")}catch(e){}})})();</script>`;
}
