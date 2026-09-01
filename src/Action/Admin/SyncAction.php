<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Data\SyncableCollections;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Renderer\JsonRenderer;

readonly class SyncAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SettingsFetcher $settingsFetcher,
		private SyncService $syncService,
		private CollectionFetcher $collectionFetcher,
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

		$post           = (array)$request->getParsedBody();
		$schemas        = $this->parseSelection($post, 'schemas');
		$templates      = $this->parseSelection($post, 'templates');
		$collections    = $this->parseCollectionsSelection($post);
		$collectionMeta = $this->parseSelection($post, 'collection_meta');
		$seed           = $this->parseSeedSelection($post);

		// The diff action compares without writing anything, so it answers
		// with its own shape (statuses per item) rather than an
		// OperationResult. Same SyncService::diff() the CLI dry-runs use —
		// the UI and the terminal can never disagree about what would change.
		if ($action === 'diff') {
			try {
				$diff = $this->syncService->diff($url, $key, $schemas, $templates, $collections, $collectionMeta);
			} catch (\Throwable $e) {
				return $this->renderer->json($response, [
					'success' => false,
					'error'   => $e->getMessage(),
				])->withStatus(502);
			}

			return $this->renderer->json($response, [
				'success'     => true,
				'diff'        => $diff,
				'collections' => $this->collectionDisplayNames($diff['objects']),
			]);
		}

		try {
			$result = match ($action) {
				// $overwrite is hard-coded false and has no form field. The
				// Sync Manager can add objects to production but never replace
				// them: overwriting is irreversible, so it stays on the CLI
				// behind --force where the operator had to type it.
				'push'  => $this->syncService->push($url, $key, $schemas, $templates, $collections, $collectionMeta, $seed, false),
				// Seeding is push-only. The section stays visible in the UI
				// (push/pull is chosen after selection, by which button you
				// click), so a pull can carry the field — drop it here.
				'pull'  => $this->syncService->pull($url, $key, $schemas, $templates, $collections, $collectionMeta),
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
	 * Resolve the Seed Objects selection into the seed-filter map.
	 *
	 * The form posts a plain list of collection ids:
	 *
	 *   seed_objects[] = blog
	 *   seed_objects[] = faq
	 *
	 * Seeding is all-or-nothing per collection, so every value in the map is
	 * null ("every object in this collection"). There is no per-object picker
	 * to serialise — a collection with thousands of rows would be unusable as
	 * a checkbox list, and the CLI already covers the surgical case with
	 * `--objects=blog:id,id`.
	 *
	 * Every id is re-checked against seedableInUi(). The rendered form only
	 * ever offers valid ones, but the form is not the boundary — a
	 * hand-crafted POST must not reach `auth`, a binary-only collection, or
	 * one that has its own section.
	 *
	 * Returns null when the request carried no selection at all, which keeps
	 * a push with nothing ticked identical to a push from before this
	 * section existed.
	 *
	 * @param array<string,mixed> $post
	 *
	 * @return array<string,null>|null
	 */
	private function parseSeedSelection(array $post): ?array
	{
		$ids = $this->parseList($post['seed_objects'] ?? []);
		if ($ids === null) {
			return null;
		}

		$out = [];
		foreach ($ids as $id) {
			if (SyncableCollections::seedableInUi($id)) {
				$out[$id] = null;
			}
		}

		return $out === [] ? null : $out;
	}

	/**
	 * Admin display name per collection appearing in the object diff, so the
	 * UI can group under the same labels the sidebar uses.
	 *
	 * @param array<string,mixed> $objectDiff keyed "collection/id"
	 *
	 * @return array<string,string>
	 */
	private function collectionDisplayNames(array $objectDiff): array
	{
		$names = [];
		foreach (array_keys($objectDiff) as $key) {
			$collectionId = str_contains((string)$key, '/') ? explode('/', (string)$key, 2)[0] : (string)$key;
			if (isset($names[$collectionId])) {
				continue;
			}
			$fallback = ucwords(str_replace(['-', '_'], ' ', $collectionId));
			try {
				$collection           = $this->collectionFetcher->fetchCollection($collectionId);
				$names[$collectionId] = $collection instanceof CollectionData && $collection->name !== '' ? $collection->name : $fallback;
			} catch (\Throwable) {
				$names[$collectionId] = $fallback;
			}
		}

		return $names;
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
