<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Docs;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Support\PathResolver;

/**
 * Total CMS Docs — bundled extension shipped with every install.
 *
 * Exposes the documentation that every Total CMS install already ships
 * (resources/docs/*.md + search-index.json, the same corpus the admin docs
 * viewer reads) through this site's MCP server as docs_search, docs_get, and
 * docs_lookup tools. This is the successor to the standalone mcp.totalcms.co
 * app (see docs/planning/official-ai-connectors.md).
 *
 * Tools default to 'authenticated' access — an anonymous caller on a
 * customer's site never sees documentation tools out of the box. Operators
 * who want a public docs MCP surface (e.g. a docs/support site whose
 * anonymous audience is Total CMS builders) can opt in via the
 * `publicTools` setting, which switches all three registrations to 'public'.
 *
 * The tool contract itself (names, descriptions, input/output schemas,
 * annotations) is unversioned relative to this file's history: it started
 * life as totalcms.co's private extension behind a submitted ChatGPT plugin,
 * so the contract stays byte-identical here — only the default access level
 * and the path-resolution mechanics changed for bundling.
 *
 * No MCP resource template ships here. The private version also exposed
 * `totalcms-docs://{group}/{page}`, but a template earns little on its own:
 * nothing is browsable in a client's attachment UI (it's a template, not
 * enumerable resources), and any caller who already knows a `group/page`
 * path got it from docs_search, at which point docs_get returns the same
 * markdown in the same round trip. Registering it also forces
 * ExtensionManager to eagerly resolve the core ResourceRegistry singleton
 * at boot — before any request-scoped collections exist — which isn't worth
 * doing for a feature nobody would notice the absence of.
 */
class Extension implements ExtensionInterface
{
	/** Maps a docs_lookup `kind` to its top-level key in reference-index.json. */
	private const KIND_INDEX_KEYS = [
		'twig_function' => 'twig_functions',
		'twig_filter'   => 'twig_filters',
		'field_type'    => 'field_types',
		'api_endpoint'  => 'api_endpoints',
		'schema_config' => 'schema_config',
		'cli_command'   => 'cli_commands',
		'extension_api' => 'extension_api',
		'builder_api'   => 'builder_api',
	];

	/** Kinds whose section is a keyed topic map (not a list of named entries). */
	private const KEYED_KINDS = ['extension_api', 'builder_api'];

	/** @var list<array<string,mixed>>|null */
	private ?array $index = null;

	/** @var array<string,mixed>|null */
	private ?array $reference = null;

	private bool $referenceLoaded = false;

	public function register(ExtensionContext $context): void
	{
		// Public-exposure opt-in (default off): most sites should keep the
		// docs tools authenticated-only. See settings-schema.json.
		$access = $context->setting('publicTools', false) ? 'public' : 'authenticated';

		// Directory requirement: every tool carries a title annotation.
		$readOnly = fn (string $title): ToolAnnotations => new ToolAnnotations(
			title: $title,
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);

		$context->registerMcpTool(
			name: 'docs_search',
			description: 'Search the Total CMS documentation. Returns matching pages with their path, title, group, and section headings. Use docs_get with a returned path to read the full page.',
			access: $access,
			handler: fn (string $query, int $limit = 8): array => $this->search($query, $limit),
			inputSchema: [
				'type'       => 'object',
				'properties' => [
					'query' => ['type' => 'string', 'description' => 'Search terms, e.g. "image resize twig" or "webhook automation"'],
					'limit' => ['type' => 'integer', 'description' => 'Maximum results (default 8, max 20)'],
				],
				'required' => ['query'],
			],
			annotations: $readOnly('Search Total CMS Docs'),
			outputSchema: $this->searchOutputSchema(),
		);

		$context->registerMcpTool(
			name: 'docs_get',
			description: 'Fetch a full Total CMS documentation page as markdown by its path (as returned by docs_search), e.g. "twig/data" or "site-builder/overview".',
			access: $access,
			handler: fn (string $path): array => $this->page($path),
			inputSchema: [
				'type'       => 'object',
				'properties' => [
					'path' => ['type' => 'string', 'description' => 'Documentation page path from docs_search results'],
				],
				'required' => ['path'],
			],
			annotations: $readOnly('Get Documentation Page'),
			outputSchema: $this->pageOutputSchema(),
		);

		$context->registerMcpTool(
			name: 'docs_lookup',
			description: 'Look up a specific Total CMS reference entry: Twig functions/filters, field types, REST API endpoints, schema configuration keys, CLI commands, extension API, or builder API. Omit `name` to list all entries of a kind.',
			access: $access,
			handler: fn (string $kind, string $name = ''): array => $this->lookup($kind, $name),
			inputSchema: [
				'type'       => 'object',
				'properties' => [
					'kind' => [
						'type' => 'string',
						'enum' => ['twig_function', 'twig_filter', 'field_type', 'api_endpoint', 'schema_config', 'cli_command', 'extension_api', 'builder_api'],
					],
					'name' => ['type' => 'string', 'description' => 'Entry name (function name, field type, "GET /api/collections", command name…). Omit to list the kind.'],
				],
				'required' => ['kind'],
			],
			annotations: $readOnly('Look Up Reference Entry'),
			outputSchema: $this->lookupOutputSchema(),
		);
	}

	public function boot(ExtensionContext $context): void
	{
	}

	// ── docs corpus access ──────────────────────────────────────────────────

	/**
	 * Root of the docs corpus. As a bundled extension this lives inside the
	 * installed package (vendor/totalcms/cms on Composer installs, the app
	 * dir on zip installs) — PathResolver::packageRoot() already resolves
	 * that for both layouts, so no reflection gymnastics are needed here
	 * (the private, out-of-package version of this extension had to walk up
	 * from ExtensionInterface's location to find the package root).
	 */
	private function docsDir(): string
	{
		return PathResolver::packageRoot() . '/resources/docs';
	}

	/** @return list<array<string,mixed>> */
	private function loadIndex(): array
	{
		if ($this->index !== null) {
			return $this->index;
		}

		$file    = $this->docsDir() . '/search-index.json';
		$decoded = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

		return $this->index = is_array($decoded) ? array_values($decoded) : [];
	}

	/**
	 * Declares the ACTUAL shape of search(), verified against both branches:
	 * the too-short-query early return (`results` empty, `message` set, no
	 * `total`/`hint`) and the normal path (`results` populated, `total` +
	 * `hint` set, no `message`). `results` is present in every case, so it's
	 * the only required property; the others are honestly optional rather
	 * than forced into a oneOf.
	 *
	 * @return array<string,mixed>
	 */
	private function searchOutputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['results'],
			'additionalProperties' => false,
			'properties'           => [
				'results' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'required'             => ['path', 'title', 'group', 'sections'],
						'additionalProperties' => false,
						'properties'           => [
							'path'     => ['type' => 'string'],
							'title'    => ['type' => 'string'],
							'group'    => ['type' => 'string'],
							'sections' => ['type' => 'array', 'items' => ['type' => 'string']],
						],
					],
				],
				'message' => ['type' => 'string', 'description' => 'Present only when the query was too short (no token of 2+ characters). No `total`/`hint` alongside it.'],
				'total'   => ['type' => 'integer', 'description' => 'Present when a search actually ran — total matches before the limit was applied.'],
				'hint'    => ['type' => 'string', 'description' => 'Present when a search actually ran — guidance for the next call.'],
			],
		];
	}

	/** @return array<string,mixed> */
	private function search(string $query, int $limit): array
	{
		$limit  = max(1, min(20, $limit));
		$tokens = array_values(array_filter(
			preg_split('/[^a-z0-9]+/i', mb_strtolower(trim($query))) ?: [],
			static fn (string $t): bool => mb_strlen($t) >= 2,
		));

		if ($tokens === []) {
			return ['results' => [], 'message' => 'Query too short — use at least one word of 2+ characters.'];
		}

		$scored = [];
		foreach ($this->loadIndex() as $entry) {
			$title    = mb_strtolower((string)($entry['title'] ?? ''));
			$path     = mb_strtolower((string)($entry['path'] ?? ''));
			$group    = mb_strtolower((string)($entry['group'] ?? ''));
			$sections = mb_strtolower(implode(' ', (array)($entry['sections'] ?? [])));
			$excerpt  = mb_strtolower((string)($entry['excerpt'] ?? ''));

			$score = 0;
			foreach ($tokens as $token) {
				if (str_contains($title, $token)) {
					$score += 8;
				}
				if (str_contains($path, $token)) {
					$score += 4;
				}
				if (str_contains($sections, $token)) {
					$score += 3;
				}
				if (str_contains($group, $token)) {
					$score += 2;
				}
				if (str_contains($excerpt, $token)) {
					$score += 1;
				}
			}

			if ($score > 0) {
				$scored[] = ['score' => $score, 'entry' => $entry];
			}
		}

		usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

		$results = array_map(static fn (array $hit): array => [
			'path'     => $hit['entry']['path'] ?? '',
			'title'    => $hit['entry']['title'] ?? '',
			'group'    => $hit['entry']['group'] ?? '',
			'sections' => $hit['entry']['sections'] ?? [],
		], array_slice($scored, 0, $limit));

		return [
			'results' => $results,
			'total'   => count($scored),
			'hint'    => $results === [] ? 'No matches. Try broader terms.' : 'Call docs_get with a path to read the full page.',
		];
	}

	/**
	 * Declares the ACTUAL shape of page(): the error variant (unknown path or
	 * missing file, `{error}`) or the success variant (`{path, title, group,
	 * markdown}`) — the two never mix, so oneOf models it exactly.
	 *
	 * @return array<string,mixed>
	 */
	private function pageOutputSchema(): array
	{
		return [
			// Root MUST declare type:'object' — the MCP spec types Tool.outputSchema
			// as {type:'object', ...} and the PHP SDK enforces it (throws otherwise);
			// a bare root oneOf with no root type is spec-invalid even though every
			// branch below is itself type:'object'.
			'type'  => 'object',
			'oneOf' => [
				[
					'type'                 => 'object',
					'required'             => ['error'],
					'additionalProperties' => false,
					'properties'           => [
						'error' => ['type' => 'string'],
					],
				],
				[
					'type'                 => 'object',
					'required'             => ['path', 'title', 'group', 'markdown'],
					'additionalProperties' => false,
					'properties'           => [
						'path'     => ['type' => 'string'],
						'title'    => ['type' => 'string'],
						'group'    => ['type' => 'string'],
						'markdown' => ['type' => 'string'],
					],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function page(string $path): array
	{
		$path = trim($path, "/ \t");

		// The index doubles as the allowlist: only paths it knows are served,
		// which removes any traversal concern without path gymnastics.
		$entry = null;
		foreach ($this->loadIndex() as $candidate) {
			if (($candidate['path'] ?? null) === $path) {
				$entry = $candidate;
				break;
			}
		}

		if ($entry === null) {
			return ['error' => "Unknown documentation path: {$path}. Use docs_search to find valid paths."];
		}

		$file = $this->docsDir() . '/' . $path . '.md';
		if (!is_file($file)) {
			return ['error' => "Documentation file missing for path: {$path}."];
		}

		return [
			'path'     => $path,
			'title'    => $entry['title'] ?? '',
			'group'    => $entry['group'] ?? '',
			'markdown' => (string)file_get_contents($file),
		];
	}

	// ── reference index lookup ──────────────────────────────────────────────

	/**
	 * Loads and memoizes resources/docs/reference-index.json. Returns null
	 * when the installed core doesn't ship it yet (older version) — callers
	 * turn that into a graceful degradation message rather than an error.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadReference(): ?array
	{
		if ($this->referenceLoaded) {
			return $this->reference;
		}
		$this->referenceLoaded = true;

		$file = $this->docsDir() . '/reference-index.json';
		if (!is_file($file)) {
			return $this->reference = null;
		}

		$decoded = json_decode((string)file_get_contents($file), true);

		return $this->reference = is_array($decoded) ? $decoded : null;
	}

	/**
	 * Identity string used for exact/contains matching within a listed kind
	 * (everything except extension_api/builder_api, which are keyed topic
	 * maps handled separately).
	 *
	 * @param array<string,mixed> $entry
	 */
	private function identityFor(string $kind, array $entry): string
	{
		return match ($kind) {
			'api_endpoint'  => trim(strtoupper((string)($entry['method'] ?? '')) . ' ' . (string)($entry['path'] ?? '')),
			'schema_config' => (string)($entry['key'] ?? ''),
			default         => (string)($entry['name'] ?? ''),
		};
	}

	/**
	 * Declares the ACTUAL shape of lookup(), which fans out across
	 * lookup()/lookupListed()/lookupKeyed(). Four mutually exclusive branches:
	 *
	 *   - error: unknown `kind`, no reference-index.json shipped, or the
	 *     section is missing — `{error}`.
	 *   - list mode: `name` omitted — `{kind, names, total}`.
	 *   - exact match: `{kind, entry}`, optionally with `topic`/`match` when
	 *     the match came from a keyed kind's (extension_api/builder_api)
	 *     item-level search rather than a direct topic-key hit. `entry`'s own
	 *     shape varies by kind (an object of fields for the six "listed"
	 *     kinds, a single-key {topic: value} object for a keyed-kind
	 *     topic-level match, or an arbitrary nested value for a keyed-kind
	 *     item-level match) — deliberately left untyped rather than
	 *     overclaiming a fixed shape.
	 *   - no match: `{kind, candidates, hint}`. Each candidate is either a raw
	 *     reference entry (listed kinds) or `{topic, match, entry}` (keyed
	 *     kinds) — same reasoning, left untyped.
	 *
	 * @return array<string,mixed>
	 */
	private function lookupOutputSchema(): array
	{
		return [
			// Root MUST declare type:'object' — see pageOutputSchema()'s comment.
			'type'  => 'object',
			'oneOf' => [
				[
					'type'                 => 'object',
					'required'             => ['error'],
					'additionalProperties' => false,
					'properties'           => [
						'error' => ['type' => 'string'],
					],
				],
				[
					'type'                 => 'object',
					'required'             => ['kind', 'names', 'total'],
					'additionalProperties' => false,
					'properties'           => [
						'kind'  => ['type' => 'string'],
						'names' => ['type' => 'array', 'items' => ['type' => 'string']],
						'total' => ['type' => 'integer'],
					],
				],
				[
					'type'                 => 'object',
					'required'             => ['kind', 'entry'],
					'additionalProperties' => false,
					'properties'           => [
						'kind'  => ['type' => 'string'],
						'entry' => ['description' => 'The matched reference entry. Shape varies by kind — see this method\'s docblock.'],
						'topic' => ['type' => 'string', 'description' => 'Present only for extension_api/builder_api item-level matches — the topic key the match was found under.'],
						'match' => ['type' => 'string', 'description' => 'Present only for extension_api/builder_api item-level matches — the matched identity string.'],
					],
				],
				[
					'type'                 => 'object',
					'required'             => ['kind', 'candidates', 'hint'],
					'additionalProperties' => false,
					'properties'           => [
						'kind'       => ['type' => 'string'],
						'candidates' => [
							'type'  => 'array',
							'items' => ['description' => 'Either a raw reference entry (listed kinds) or {topic, match, entry} (keyed kinds) — see this method\'s docblock.'],
						],
						'hint' => ['type' => 'string'],
					],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private function lookup(string $kind, string $name): array
	{
		$indexKey = self::KIND_INDEX_KEYS[$kind] ?? null;
		if ($indexKey === null) {
			return ['error' => "Unknown kind: {$kind}."];
		}

		$reference = $this->loadReference();
		if ($reference === null) {
			return ['error' => 'This Total CMS version does not ship reference-index.json — docs_search still works.'];
		}

		$section = $reference[$indexKey] ?? null;
		if (!is_array($section)) {
			return ['error' => "No {$kind} data available in reference-index.json."];
		}

		return in_array($kind, self::KEYED_KINDS, true)
			? $this->lookupKeyed($section, $kind, $name)
			: $this->lookupListed($section, $kind, $name);
	}

	/**
	 * Handles the list-of-named-entries kinds: twig_function, twig_filter,
	 * field_type, api_endpoint, schema_config, cli_command.
	 *
	 * @param list<array<string,mixed>> $entries
	 * @return array<string,mixed>
	 */
	private function lookupListed(array $entries, string $kind, string $name): array
	{
		if (trim($name) === '') {
			$names = array_values(array_map(fn (array $e): string => $this->identityFor($kind, $e), $entries));
			sort($names);

			return ['kind' => $kind, 'names' => $names, 'total' => count($names)];
		}

		$needle = mb_strtolower(trim($name));

		foreach ($entries as $entry) {
			if (mb_strtolower($this->identityFor($kind, $entry)) === $needle) {
				return ['kind' => $kind, 'entry' => $entry];
			}
		}

		$candidates = [];
		foreach ($entries as $entry) {
			if (str_contains(mb_strtolower($this->identityFor($kind, $entry)), $needle)) {
				$candidates[] = $entry;
				if (count($candidates) >= 5) {
					break;
				}
			}
		}

		return [
			'kind'       => $kind,
			'candidates' => $candidates,
			'hint'       => $candidates === []
				? "No {$kind} entries match \"{$name}\". Omit name to list all entries of this kind."
				: "No exact match for \"{$name}\" — showing closest candidates.",
		];
	}

	/**
	 * Handles extension_api and builder_api: each is a keyed topic map
	 * (min_version, note, context_methods, events, …) rather than a list of
	 * named entries, so the identity field is the object key itself.
	 *
	 * @param array<string,mixed> $section
	 * @return array<string,mixed>
	 */
	private function lookupKeyed(array $section, string $kind, string $name): array
	{
		$keys = array_keys($section);

		if (trim($name) === '') {
			sort($keys);

			return ['kind' => $kind, 'names' => $keys, 'total' => count($keys)];
		}

		$needle = mb_strtolower(trim($name));

		foreach ($keys as $key) {
			if (mb_strtolower($key) === $needle) {
				return ['kind' => $kind, 'entry' => [$key => $section[$key]]];
			}
		}

		// No topic-level match — search item-level entries nested inside each
		// topic (e.g. extension_api.context_methods[].name, .events[].name,
		// builder_api.twig_functions[].name…) so lookups like "addTwigFunction"
		// or "object.created" resolve without the caller knowing which topic
		// holds them.
		$items = [];
		foreach ($section as $topic => $value) {
			$this->collectNamedItems($value, (string)$topic, 4, $items);
		}

		foreach ($items as $item) {
			if (mb_strtolower($item['match']) === $needle) {
				return ['kind' => $kind, 'topic' => $item['topic'], 'match' => $item['match'], 'entry' => $item['entry']];
			}
		}

		$candidates = [];
		foreach ($items as $item) {
			if (str_contains(mb_strtolower($item['match']), $needle)) {
				$candidates[] = $item;
				if (count($candidates) >= 5) {
					break;
				}
			}
		}

		// Fall back to fuzzy topic-key matches (e.g. "conte" -> "context_methods")
		// when nothing inside any topic matched either.
		if ($candidates === []) {
			foreach ($keys as $key) {
				if (str_contains(mb_strtolower($key), $needle)) {
					$candidates[] = ['topic' => $key, 'match' => $key, 'entry' => $section[$key]];
					if (count($candidates) >= 5) {
						break;
					}
				}
			}
		}

		return [
			'kind'       => $kind,
			'candidates' => $candidates,
			'hint'       => $candidates === []
				? "No {$kind} entries match \"{$name}\". Omit name to list all entries of this kind."
				: "No exact match for \"{$name}\" — showing closest candidates.",
		];
	}

	/**
	 * Recursively collects nameable items out of a topic's nested value: list
	 * items that are plain strings, or list items with a string 'name'/'key'
	 * field, walked up to $depth levels deep. Appends {topic, match, entry}
	 * rows to $out. Deliberately schema-agnostic — no assumption beyond
	 * "strings and 'name' fields identify things" per the reference index's
	 * loose, hand-authored shape.
	 *
	 * @param list<array{topic:string,match:string,entry:mixed}> $out
	 */
	private function collectNamedItems(mixed $node, string $topic, int $depth, array &$out): void
	{
		if ($depth <= 0 || !is_array($node)) {
			return;
		}

		if (array_is_list($node)) {
			foreach ($node as $item) {
				if (is_string($item)) {
					$out[] = ['topic' => $topic, 'match' => $item, 'entry' => $item];

					continue;
				}

				if (is_array($item)) {
					$identity = null;
					if (isset($item['name']) && is_string($item['name'])) {
						$identity = $item['name'];
					} elseif (isset($item['key']) && is_string($item['key'])) {
						$identity = $item['key'];
					}

					if ($identity !== null) {
						$out[] = ['topic' => $topic, 'match' => $identity, 'entry' => $item];
					}

					$this->collectNamedItems($item, $topic, $depth - 1, $out);
				}
			}

			return;
		}

		foreach ($node as $value) {
			if (is_array($value)) {
				$this->collectNamedItems($value, $topic, $depth - 1, $out);
			}
		}
	}
}
