<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Renderer\JsonRenderer;

readonly class SyncAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SettingsFetcher $settingsFetcher,
		private SyncService $syncService,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$action = $args['action'] ?? '';

		// Validate sync is configured
		$syncSettings = $this->settingsFetcher->loadSection('sync');
		$url          = trim((string)($syncSettings['url'] ?? ''));
		$key          = trim((string)($syncSettings['key'] ?? ''));

		if ($url === '' || $key === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => 'Sync not configured. Set the production URL and API key in Settings > Sync.',
			])->withStatus(400);
		}

		$post        = (array)$request->getParsedBody();
		$schemas     = $this->parseSelection($post, 'schemas');
		$templates   = $this->parseSelection($post, 'templates');
		$collections = $this->parseCollectionsSelection($post);

		try {
			$result = match ($action) {
				'push'  => $this->syncService->push($url, $key, $schemas, $templates, $collections),
				'pull'  => $this->syncService->pull($url, $key, $schemas, $templates, $collections),
				default => throw new \InvalidArgumentException("Unknown sync action: {$action}"),
			};
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => $e->getMessage(),
			])->withStatus(400);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => $e->getMessage(),
			])->withStatus(502);
		}

		return $this->renderer->json($response, $result->toArray());
	}

	/**
	 * Resolve a section's selection into an exporter filter:
	 *   - mode=all      → null  (export everything)
	 *   - mode=none     → []    (export nothing — filter matches no records)
	 *   - mode=specific → list<string> of selected ids
	 *
	 * The mode flag is sent by the admin UI so "user picked nothing" can be
	 * distinguished from "user picked everything" — both used to collapse to
	 * null and unintentionally push the whole category. For requests that
	 * predate the mode flag (CLI scripts, older integrations), default to the
	 * original "missing = all" behaviour by reading the bare list when no
	 * mode is present.
	 *
	 * @param array<string,mixed> $post
	 *
	 * @return list<string>|null
	 */
	private function parseSelection(array $post, string $key): ?array
	{
		$mode = (string)($post[$key . '_mode'] ?? '');

		return match ($mode) {
			'all'      => null,
			'none'     => [],
			'specific' => $this->parseList($post[$key] ?? []),
			default    => $this->parseList($post[$key] ?? []) ?? null,
		};
	}

	/**
	 * @return list<string>|null
	 */
	private function parseList(mixed $value): ?array
	{
		if (is_array($value) && $value !== []) {
			return array_values(array_filter(array_map(strval(...), $value)));
		}

		return null;
	}

	/**
	 * Resolve the per-collection selection into the map shape SyncService
	 * expects. Form payload structure (one block per allowlisted collection
	 * that has a UI section):
	 *
	 *   collections[builder-pages][mode]    = all | specific | none
	 *   collections[builder-pages][items][] = home
	 *   collections[builder-pages][items][] = about
	 *
	 *   - mode=all       → ['builder-pages' => null]  (all objects)
	 *   - mode=specific  → ['builder-pages' => ['home', 'about']]
	 *   - mode=none      → key omitted from the map (skip this collection)
	 *   - block missing  → key omitted from the map
	 *
	 * Returns null when the request didn't carry the `collections` block at
	 * all, preserving the back-compat "export everything" path for callers
	 * (CLI, older clients) that predate the per-collection UI.
	 *
	 * @param array<string,mixed> $post
	 *
	 * @return array<string,list<string>|null>|null
	 */
	private function parseCollectionsSelection(array $post): ?array
	{
		$raw = $post['collections'] ?? null;
		if (!is_array($raw)) {
			return null;
		}

		$out = [];
		foreach (SyncableCollections::IDS as $id) {
			$block = $raw[$id] ?? null;
			if (!is_array($block)) {
				continue;
			}
			$mode = (string)($block['mode'] ?? '');
			switch ($mode) {
				case 'all':
					$out[$id] = null;
					break;
				case 'specific':
					$items    = $block['items'] ?? [];
					$out[$id] = is_array($items)
						? array_values(array_filter(array_map(strval(...), $items)))
						: [];
					break;
				case 'none':
				default:
					// Skip — leave the key out of the map so the exporter
					// doesn't iterate this collection at all.
					break;
			}
		}

		return $out;
	}
}
