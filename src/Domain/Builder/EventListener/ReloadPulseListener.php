<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\EventListener;

use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderReloadPulseService;

/**
 * Bumps the reload pulse whenever a Builder template or page record is saved,
 * so connected pages reload via the SSE endpoint.
 *
 * Wired into `EventDispatcher` in `config/container.php` for four events:
 *
 *   - `template.saved`   — any builder template change
 *   - `object.created`   — filtered to the pages collection
 *   - `object.updated`   — filtered to the pages collection
 *   - `devmode.disabled` — pulses once so already-connected browsers reload
 *                          cleanly into a page that no longer carries the
 *                          live-reload script (rather than reconnect-looping
 *                          against a 403)
 *
 * Object filtering keeps the pulse from firing on every collection save in
 * the system — only the collection that backs Builder pages should trigger
 * a public-page reload.
 *
 * The dispatcher converts typed `EventPayload` instances to arrays before
 * invoking listeners, so all listener methods take `array $payload`.
 */
final readonly class ReloadPulseListener
{
	public function __construct(
		private BuilderReloadPulseService $pulse,
		private BuilderConfigService $builderConfig,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onTemplateSaved(array $payload): void
	{
		$path = (string)($payload['path'] ?? $payload['id'] ?? '');
		$this->pulse->pulse($path);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectChanged(array $payload): void
	{
		$collection = (string)($payload['collection'] ?? '');
		if ($collection !== $this->builderConfig->getPagesCollectionId()) {
			return;
		}

		$id = (string)($payload['id'] ?? '');
		$this->pulse->pulse($collection . '/' . $id);
	}

	public function onDevModeDisabled(): void
	{
		$this->pulse->pulse('devmode-disabled');
	}
}
