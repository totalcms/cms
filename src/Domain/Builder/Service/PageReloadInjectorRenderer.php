<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Support\Config;

/**
 * Injects the live-reload `<script>` snippet into rendered HTML when Dev Mode
 * is active. The snippet opens an `EventSource` to `/livereload/events` and
 * calls `location.reload()` on each `reload` event.
 *
 * Dev Mode is the sole gate. Without it, Twig serves cached output, so
 * reloading the browser would show the same stale page — the feature only
 * does useful work when caching is bypassed. Dev Mode being on also signals
 * "this is a development context, broadcast freely" — the snippet is injected
 * for every visitor (not just admins), which is what makes the demo case work
 * (developer + client both watching, both reload on save).
 *
 * The script is small (no dependencies, no globals beyond the EventSource
 * itself) and self-contained — keeps it from interacting with anything the
 * page already loads.
 */
readonly class PageReloadInjectorRenderer
{
	public function __construct(
		private DevModeManager $devModeManager,
		private Config $config,
	) {
	}

	/**
	 * Inject the snippet into the rendered HTML body, or return the body
	 * unchanged if Dev Mode is off.
	 */
	public function maybeInject(string $body, ServerRequestInterface $request): string
	{
		if (!$this->devModeManager->isDevModeActive()) {
			return $body;
		}

		return $this->injectBeforeBodyClose($body, $this->renderSnippet());
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
		$url  = $base . '/livereload/events';

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
