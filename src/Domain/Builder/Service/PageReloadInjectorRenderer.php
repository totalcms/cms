<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Support\Config;

/**
 * Injects the live-reload `<script>` snippet into rendered HTML for admin
 * sessions when the `liveReload` Builder setting is enabled.
 *
 * Companion to {@see PageInspectorRenderer} — same gating model, separate
 * concern. The snippet opens an `EventSource` to `/admin/builder/events` and
 * calls `location.reload()` on each `reload` event.
 *
 * Visibility rules:
 *   - Admin session only (`AccessManager::sessionHasUser()`)
 *   - HTML response (caller checks Content-Type)
 *   - `liveReload` setting enabled
 *
 * The script is small (no dependencies, no globals beyond the EventSource
 * itself) and self-contained — keeps it from interacting with anything the
 * page already loads.
 */
readonly class PageReloadInjectorRenderer
{
	public function __construct(
		private AccessManager $accessManager,
		private BuilderConfigService $builderConfig,
		private Config $config,
	) {
	}

	/**
	 * Inject the snippet into the rendered HTML body, or return the body
	 * unchanged if injection conditions aren't met.
	 */
	public function maybeInject(string $body, ServerRequestInterface $request): string
	{
		if (!$this->shouldInject($request)) {
			return $body;
		}

		return $this->injectBeforeBodyClose($body, $this->renderSnippet());
	}

	private function shouldInject(ServerRequestInterface $request): bool
	{
		if (!$this->accessManager->sessionHasUser()) {
			return false;
		}

		return $this->builderConfig->isLiveReloadEnabled();
	}

	/**
	 * Insert the snippet immediately before the LAST `</body>` tag — same
	 * approach as the inspector. Falls back to appending if no closing body
	 * tag is found.
	 */
	private function injectBeforeBodyClose(string $body, string $snippet): string
	{
		$pos = strripos($body, '</body>');
		if ($pos === false) {
			return $body . $snippet;
		}

		return substr($body, 0, $pos) . $snippet . substr($body, $pos);
	}

	private function renderSnippet(): string
	{
		$base = rtrim($this->config->api, '/');
		$url  = $base . '/admin/builder/events';

		// Inline JS — no external assets, no module loading, no globals
		// beyond the EventSource instance held by the IIFE closure.
		return <<<HTML
<script data-totalcms="builder-live-reload">
(function(){
if (typeof EventSource === 'undefined') return;
var es = new EventSource('{$url}');
es.addEventListener('reload', function(){
es.close();
location.reload();
});
es.addEventListener('error', function(){
// EventSource auto-reconnects on transient errors. We only act on a
// permanent close (readyState 2) to avoid churn during reconnects.
if (es.readyState === 2) { /* closed */ }
});
})();
</script>
HTML;
	}
}
