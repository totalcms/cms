<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Support\Config;

/**
 * Renders the admin-only Page Inspector overlay for builder-page and
 * collection-URL renders. The overlay surfaces the matched route, page
 * id, template, status, and active features, plus an "Edit page" / "Edit
 * object" link that drops the admin into the right editor.
 *
 * Server-side injection is the right call for this:
 *
 *   - We already have the RouteMatch — no need to repeat work client-side.
 *   - No JS-only conditional means an unauthorized response inspection
 *     can never leak admin-only data.
 *   - Works without JS enabled in the visitor's browser.
 *   - Snippet is self-contained (HTML + scoped CSS + tiny inline JS), no
 *     extra HTTP request.
 *
 * Injection is gated to:
 *   - Logged-in admin sessions only
 *   - Visitors who haven't dismissed the chip (cookie `tcms_inspector_hidden`)
 *
 * The HTML content-type check lives in the caller (PageRouterMiddleware) —
 * the renderer doesn't see the response object.
 */
readonly class PageInspectorRenderer
{
	public const DISMISS_COOKIE = 'tcms_inspector_hidden';

	public function __construct(
		private AccessManager $accessManager,
		private Config $config,
	) {
	}

	/**
	 * Inject the inspector chip into the rendered HTML body, or return the
	 * body unchanged if injection conditions aren't met.
	 */
	public function maybeInject(string $body, ServerRequestInterface $request, RouteMatch $match): string
	{
		if (!$this->shouldInject($request)) {
			return $body;
		}

		return $this->injectBeforeBodyClose($body, $this->renderSnippet($match));
	}

	private function shouldInject(ServerRequestInterface $request): bool
	{
		if (!$this->accessManager->sessionHasUser()) {
			return false;
		}

		$cookies = $request->getCookieParams();

		return ($cookies[self::DISMISS_COOKIE] ?? '') !== '1';
	}

	/**
	 * Insert the snippet immediately before the LAST `</body>` tag (using
	 * strripos so any literal `</body>` strings inside content earlier in
	 * the page can't trip the injection point). Falls back to appending if
	 * no closing body tag is found — better to render the chip somewhere
	 * than nowhere.
	 */
	private function injectBeforeBodyClose(string $body, string $snippet): string
	{
		$pos = strripos($body, '</body>');
		if ($pos === false) {
			return $body . $snippet;
		}

		return substr($body, 0, $pos) . $snippet . substr($body, $pos);
	}

	/**
	 * Build the chip HTML + scoped CSS + minimal JS. Self-contained — no
	 * external assets, no global namespace pollution beyond the cookie name.
	 */
	private function renderSnippet(RouteMatch $match): string
	{
		$page         = new PageData($match->pageData);
		$isCollection = $match->collection !== null;

		$title    = $isCollection
			? ($page->title !== '' ? $page->title : (string)$match->pageData['id'])
			: $page->title;
		$id       = $page->id !== '' ? $page->id : (string)$match->pageData['id'];
		$adminUrl = $this->adminEditUrl($match, $page);

		$rows = [
			['Match',     $isCollection ? 'collection (' . $match->collection . ')' : 'builder page'],
			[$isCollection ? 'Object id' : 'Page id', $id],
			['Template',  $match->template],
			['Route',     $isCollection ? '/' . ltrim($page->route ?? '', '/') : $page->route],
			['Status',    (string)$match->status],
		];

		if ($match->params !== []) {
			$paramPairs = [];
			foreach ($match->params as $k => $v) {
				$paramPairs[] = htmlspecialchars($k) . '=' . htmlspecialchars((string)$v);
			}
			$rows[] = ['Params', implode(', ', $paramPairs)];
		}

		if (!$isCollection && $page->middleware !== []) {
			$rows[] = ['Features', implode(', ', $page->middleware)];
		}

		$rowsHtml = '';
		foreach ($rows as [$label, $value]) {
			if ($value === '') {
				continue;
			}
			$rowsHtml .= '<dt>' . htmlspecialchars($label) . '</dt><dd>'
				. htmlspecialchars($value) . '</dd>';
		}

		$editLabel = $isCollection ? 'Edit object' : 'Edit page';
		$displayId = $title !== '' ? $title : $id;

		// Inline everything — keeps the chip a single self-contained chunk
		// with no risk of being affected by the rendered page's CSS.
		// Style rules are scoped under #t3-inspector to minimize spillover;
		// we also use `all: revert` on the wrapper to insulate from page CSS.
		return <<<HTML
<aside id="t3-inspector" data-state="collapsed" aria-label="Total CMS Page Inspector">
<style>
#t3-inspector { all: revert; position: fixed; bottom: 1rem; right: 1rem; z-index: 2147483647;
font-family: -apple-system, BlinkMacSystemFont, system-ui, sans-serif; font-size: 13px; line-height: 1.4;
color: #f3f4f6; max-width: 380px; box-shadow: 0 8px 24px rgba(0,0,0,.25); border-radius: 8px;
background: #1f2937; print-color-adjust: exact; }
@media print { #t3-inspector { display: none !important; } }
#t3-inspector * { box-sizing: border-box; }
#t3-inspector .t3i-toggle { display: flex; align-items: center; gap: .5rem; padding: .5rem .75rem;
background: transparent; border: 0; color: inherit; font: inherit; cursor: pointer; width: 100%; text-align: left; }
#t3-inspector .t3i-badge { background: #2563eb; color: #fff; font-weight: 600; font-size: 11px;
padding: 2px 6px; border-radius: 4px; letter-spacing: .04em; }
#t3-inspector .t3i-title { font-weight: 600; max-width: 16ch; overflow: hidden; text-overflow: ellipsis;
white-space: nowrap; }
#t3-inspector .t3i-route { color: #9ca3af; font-family: ui-monospace, SFMono-Regular, monospace;
font-size: 12px; max-width: 14ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#t3-inspector .t3i-chev { margin-left: auto; transition: transform .15s; opacity: .6; }
#t3-inspector[data-state="expanded"] .t3i-chev { transform: rotate(180deg); }
#t3-inspector .t3i-body { display: none; padding: 0 .75rem .75rem; border-top: 1px solid #374151; }
#t3-inspector[data-state="expanded"] .t3i-body { display: block; }
#t3-inspector dl { margin: .5rem 0; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; }
#t3-inspector dt { color: #9ca3af; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
align-self: center; }
#t3-inspector dd { margin: 0; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px;
color: #f3f4f6; word-break: break-all; }
#t3-inspector .t3i-actions { display: flex; gap: .25rem; padding-top: .5rem; }
#t3-inspector .t3i-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center;
padding: .375rem .75rem; background: #2563eb; color: #fff; border: 0; border-radius: 4px;
text-decoration: none; font: inherit; cursor: pointer; }
#t3-inspector .t3i-btn:hover { background: #1d4ed8; }
#t3-inspector .t3i-dismiss { flex: 0 0 32px; background: transparent; color: #9ca3af; }
#t3-inspector .t3i-dismiss:hover { background: #374151; color: #fff; }
</style>
<button type="button" class="t3i-toggle" aria-expanded="false">
<span class="t3i-badge">T3</span>
<span class="t3i-title">{$this->esc($displayId)}</span>
<span class="t3i-route">{$this->esc((string)($match->pageData['route'] ?? ''))}</span>
<span class="t3i-chev" aria-hidden="true">▾</span>
</button>
<div class="t3i-body">
<dl>{$rowsHtml}</dl>
<div class="t3i-actions">
<a class="t3i-btn" href="{$this->esc($adminUrl)}">{$editLabel}</a>
<button type="button" class="t3i-btn t3i-dismiss" aria-label="Dismiss inspector" title="Hide for 30 days">&times;</button>
</div>
</div>
<script>
(function(){
var el = document.getElementById('t3-inspector');
if (!el) return;
var toggle = el.querySelector('.t3i-toggle');
toggle.addEventListener('click', function(){
var expanded = el.getAttribute('data-state') === 'expanded';
el.setAttribute('data-state', expanded ? 'collapsed' : 'expanded');
toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
});
el.querySelector('.t3i-dismiss').addEventListener('click', function(e){
e.stopPropagation();
var d = new Date(); d.setTime(d.getTime() + 30*24*60*60*1000);
document.cookie = 'tcms_inspector_hidden=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
el.style.display = 'none';
});
})();
</script>
</aside>
HTML;
	}

	/**
	 * Compute the admin edit URL — different for builder-page vs collection-URL
	 * matches. Builder pages go to the page form; collection objects go to the
	 * object editor.
	 */
	private function adminEditUrl(RouteMatch $match, PageData $page): string
	{
		$base = rtrim($this->config->api, '/');

		if ($match->collection !== null) {
			$objectId = (string)($match->pageData['id'] ?? '');

			return $base . '/admin/collections/' . $match->collection . '/' . $objectId;
		}

		return $base . '/admin/builder/page/' . $page->id;
	}

	private function esc(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
}
