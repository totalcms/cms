<?php

declare(strict_types=1);

/**
 * Markdown parsers that build resources/docs/reference-index.json.
 *
 * Ported from mcp.totalcms.co's bin/index-parsers.php. That project reflected
 * and parsed an EXTERNAL totalcms checkout passed in as a CLI path argument.
 * Here the generator lives inside the codebase it documents, so every path is
 * rebased onto this repo's resources/docs/ directly — no vendored-path
 * resolution. Parsing logic is unchanged from the source implementation.
 *
 * The `pages` section of the old index is intentionally dropped — it's
 * redundant with resources/docs/search-index.json, which already covers
 * full-text page search. See docs/planning/docs-mcp-extension-plan.md.
 */

use Symfony\Component\Yaml\Yaml;

// =======================================================
// Parsing functions
// =======================================================

/**
 * @return array<string, mixed>
 */
function parseFrontmatter(string $content): array
{
	if (!preg_match('/^---\s*\n(.*?)\n---/s', $content, $match)) {
		return [];
	}

	try {
		$parsed = Yaml::parse($match[1]);
	} catch (\Throwable) {
		return [];
	}

	return is_array($parsed) ? $parsed : [];
}

function removeFrontmatter(string $content): string
{
	return (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
}

function cleanForSearch(string $body): string
{
	// Remove code blocks but keep content
	$clean = (string) preg_replace('/```\w*\n?/', '', $body);
	// Remove HTML tags
	$clean = strip_tags($clean);
	// Remove markdown formatting (preserve dots for cms.method names)
	$clean = (string) preg_replace('/[#*_`\[\]()]/', ' ', $clean);
	// Remove URLs
	$clean = (string) preg_replace('/https?:\/\/[^\s]+/', '', $clean);
	// Remove Twig delimiters
	$clean = (string) preg_replace('/\{\{|\}\}|\{%|%\}|\{#|#\}/', ' ', $clean);
	// Normalize whitespace
	$clean = (string) preg_replace('/\s+/', ' ', $clean);
	return strtolower(trim($clean));
}

/**
 * Parse filter signatures from filters.md.
 * Pattern: #### `filterName(params): returnType`
 *
 * @return array<int, array<string, mixed>>
 */
function parseFilterSignatures(string $content): array
{
	$filters = [];
	$body = removeFrontmatter($content);

	// Split by H4 headings that contain backtick signatures
	$parts = preg_split('/^####\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '' || !str_starts_with($part, '`')) {
			continue;
		}

		// Extract signature from first line
		if (!preg_match('/^`([^`]+)`\s*$/m', $part, $sigMatch)) {
			continue;
		}

		$signature = $sigMatch[1];

		// Parse name from signature (before the opening paren)
		if (preg_match('/^(\w+)/', $signature, $nameMatch)) {
			$name = $nameMatch[1];
		} else {
			continue;
		}

		// Get description (text after signature line, before first code block)
		$descriptionLines = [];
		$lines = explode("\n", $part);
		$pastSignature = false;
		foreach ($lines as $line) {
			if (!$pastSignature) {
				if (str_contains($line, '`' . $signature . '`') || str_starts_with($line, '`')) {
					$pastSignature = true;
				}
				continue;
			}
			$trimmed = trim($line);
			if ($trimmed === '' && !empty($descriptionLines)) {
				break;
			}
			if (str_starts_with($trimmed, '```')) {
				break;
			}
			if ($trimmed !== '') {
				$descriptionLines[] = $trimmed;
			}
		}
		$description = implode(' ', $descriptionLines);

		// Extract code examples
		$examples = extractCodeBlocks($part);

		$filters[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/twig/filters/',
		];
	}

	return $filters;
}

/**
 * Parse standalone function signatures from functions.md.
 * Pattern: ### `functionName(params): returnType`
 *
 * @return array<int, array<string, mixed>>
 */
function parseFunctionSignatures(string $content): array
{
	$functions = [];
	$body = removeFrontmatter($content);

	// Split by H3 headings that contain backtick signatures
	$parts = preg_split('/^###\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '' || !str_starts_with($part, '`')) {
			continue;
		}

		if (!preg_match('/^`([^`]+)`/m', $part, $sigMatch)) {
			continue;
		}

		$signature = $sigMatch[1];

		if (preg_match('/^(\w+)/', $signature, $nameMatch)) {
			$name = $nameMatch[1];
		} else {
			continue;
		}

		$description = extractDescription($part);
		$examples = extractCodeBlocks($part);

		$functions[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/twig/functions/',
		];
	}

	return $functions;
}

/**
 * Parse cms.* namespace functions from collection/data/media docs.
 * Pattern: ### methodName() or ## methodName()
 *
 * @return array<int, array<string, mixed>>
 */
function parseNamespaceFunctions(string $content, string $sourceFile): array
{
	$functions = [];
	$body = removeFrontmatter($content);

	// Determine the namespace from the H1 heading (e.g., "# cms.collection")
	$namespace = '';
	if (preg_match('/^#\s+(cms\.\w+)/m', $body, $nsMatch)) {
		$namespace = $nsMatch[1];
	}

	// Determine URL from source file
	$urlPath = str_replace('.md', '/', $sourceFile);
	$url = 'https://docs.totalcms.co/' . $urlPath;

	// Split by H3 headings (method names)
	$parts = preg_split('/^###\s+/m', $body);

	foreach ($parts as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}

		// Get method name from first line
		$firstLine = strtok($part, "\n");
		if ($firstLine === false) {
			continue;
		}

		// Clean up the method name — remove backticks, trailing parens
		$methodName = trim($firstLine);
		$methodName = trim($methodName, '`');

		// Skip headings that don't look like method/function names
		if (str_contains($methodName, ' ') && !str_contains($methodName, '(')) {
			continue;
		}

		// Remove trailing () if present
		$cleanName = rtrim($methodName, '()');

		// Build full qualified name
		$fullName = $namespace ? $namespace . '.' . $cleanName : $cleanName;

		$description = extractDescription($part);
		$examples = extractCodeBlocks($part);

		if (!empty($description) || !empty($examples)) {
			$functions[] = [
				'name'        => $fullName,
				'signature'   => $methodName,
				'description' => $description,
				'examples'    => $examples,
				'url'         => $url,
			];
		}
	}

	return $functions;
}

/**
 * Parse REST API endpoints from rest-api.md.
 * Pattern: ```http\nMETHOD /path\n```
 *
 * @return array<int, array<string, mixed>>
 */
function parseApiEndpoints(string $content): array
{
	$endpoints = [];
	$body = removeFrontmatter($content);

	// Split into sections by H3 headings
	$sections = preg_split('/^###\s+/m', $body);

	foreach ($sections as $section) {
		$section = trim($section);
		if ($section === '') {
			continue;
		}

		$sectionTitle = strtok($section, "\n");

		// Find HTTP method/path blocks
		if (preg_match_all('/```http\s*\n\s*(GET|POST|PUT|PATCH|DELETE)\s+(\S+)\s*\n```/i', $section, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$method = strtoupper($match[1]);
				$path = $match[2];

				// Get description: text between heading and first code block
				$descText = '';
				if (preg_match('/^[^\n]+\n\n(.+?)(?:\n\n```|\n```)/s', $section, $descMatch)) {
					$candidate = trim($descMatch[1]);
					// Only use if it doesn't start with a code fence
					if (!str_starts_with($candidate, '```')) {
						$descText = (string) preg_replace('/\s+/', ' ', $candidate);
					}
				}
				$description = $descText ?: (string) $sectionTitle;

				// Look for parameters table
				$parameters = [];
				if (preg_match_all('/\|\s*`(\w+)`\s*\|\s*(\w+)\s*\|\s*([^|]+)\|/m', $section, $paramMatches, PREG_SET_ORDER)) {
					foreach ($paramMatches as $pm) {
						$parameters[] = [
							'name'        => $pm[1],
							'type'        => trim($pm[2]),
							'description' => trim($pm[3]),
						];
					}
				}

				// Check for edition info
				$edition = 'lite';
				if (preg_match('/pro\s+edition/i', $section)) {
					$edition = 'pro';
				} elseif (preg_match('/standard\s+edition/i', $section)) {
					$edition = 'standard';
				}

				$endpoints[] = [
					'method'      => $method,
					'path'        => $path,
					'title'       => $sectionTitle ?: '',
					'description' => $description,
					'parameters'  => $parameters,
					'edition'     => $edition,
					'url'         => 'https://docs.totalcms.co/apis/rest-api/',
				];
			}
		}
	}

	return $endpoints;
}

/**
 * Parse schema/collection configuration options from settings.md.
 * Pattern: ### key\n\n**Type:** `type`
 *
 * @return array<int, array<string, mixed>>
 */
function parseSchemaConfig(string $content): array
{
	$configs = [];
	$body = removeFrontmatter($content);

	// Split by H3 headings
	$sections = preg_split('/^###\s+/m', $body);

	foreach ($sections as $section) {
		$section = trim($section);
		if ($section === '') {
			continue;
		}

		$key = trim(strtok($section, "\n"));

		// Skip headings that look like prose rather than config keys
		if (str_contains($key, ' ') && strlen($key) > 30) {
			continue;
		}

		// Skip headings that are markdown artifacts (e.g. "# Title")
		if (str_starts_with($key, '#')) {
			continue;
		}

		// Extract type
		$type = '';
		if (preg_match('/\*\*Type:\*\*\s*`([^`]+)`/', $section, $typeMatch)) {
			$type = $typeMatch[1];
		}

		// Extract required
		$required = false;
		if (preg_match('/\*\*Required:\*\*\s*(Yes|true)/i', $section)) {
			$required = true;
		}

		// Extract default
		$default = null;
		if (preg_match('/\*\*Default:\*\*\s*`([^`]*)`/', $section, $defMatch)) {
			$default = $defMatch[1];
		}

		$description = extractDescription($section);
		$examples = extractCodeBlocks($section);

		$configs[] = [
			'key'         => $key,
			'type'        => $type,
			'required'    => $required,
			'default'     => $default,
			'description' => $description,
			'examples'    => $examples,
			'url'         => 'https://docs.totalcms.co/collections/settings/',
		];
	}

	return $configs;
}

// =======================================================
// Helper functions
// =======================================================

/**
 * Extract the first paragraph of description text after the heading line.
 */
function extractDescription(string $section): string
{
	$lines = explode("\n", $section);
	$descLines = [];
	$started = false;

	foreach ($lines as $i => $line) {
		if ($i === 0) {
			continue; // Skip heading line
		}

		$trimmed = trim($line);

		// Skip empty lines before description starts
		if (!$started && $trimmed === '') {
			continue;
		}

		// Stop at code blocks, headings, tables, or blank lines after content
		if ($started && ($trimmed === '' || str_starts_with($trimmed, '```') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '|'))) {
			break;
		}

		// Skip metadata lines like **Type:** or **Required:**
		if (preg_match('/^\*\*\w+:\*\*/', $trimmed)) {
			if ($started) {
				break;
			}
			continue;
		}

		$started = true;
		$descLines[] = $trimmed;
	}

	return implode(' ', $descLines);
}

/**
 * Extract code blocks from a section.
 *
 * @return string[]
 */
function extractCodeBlocks(string $section): array
{
	$examples = [];
	if (preg_match_all('/```(\w*)\n(.*?)```/s', $section, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			$code = trim($match[2]);
			if ($code !== '') {
				$examples[] = $code;
			}
		}
	}
	// Limit to first 3 examples per entry
	return array_slice($examples, 0, 3);
}

/**
 * Command-heading shape guard shared by the H2 and H3 extraction passes in
 * parseCliCommands(). A heading only introduces a command if its line is
 * NOTHING but a backtick-wrapped token (round-1 fix: flag-behavior
 * subsections like `--force` Behavior carry trailing prose after the
 * closing backtick and must be rejected) AND that token is shaped like an
 * actual `tcms` command name — a lowercase word, optionally namespaced with
 * a single colon segment (`info`, `push`, `schema:list`, `builder:init`).
 * The shape check is belt-and-suspenders against any future bare-backtick
 * heading (e.g. a single flag name with no trailing text) being mistaken
 * for a command.
 *
 * @return string|null the command name, or null if this heading isn't one
 */
function matchCliCommandName(string $headingLine): ?string
{
	if (!preg_match('/^`([^`]+)`$/', trim($headingLine), $nameMatch)) {
		return null;
	}

	$name = $nameMatch[1];
	if (!preg_match('/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)?$/', $name)) {
		return null;
	}

	return $name;
}

/**
 * Extract one command's fields (description, examples, options, arguments)
 * from its heading-delimited section body. Shared by both extraction passes
 * in parseCliCommands() — the H2-level pass hands in a whole command block
 * including everything nested under it (site-builder/cli.md's `--force`
 * Behavior, Arguments, Options, etc. all live several H3s deep under one H2
 * command); the H3-level pass hands in a single flat H3 block
 * (extensions/cli.md, where Options/Arguments tables sit directly under the
 * command heading with no further nesting). Both shapes contain the same
 * markdown constructs (a description paragraph, fenced code examples,
 * pipe-table rows) so one field-extraction routine covers both.
 *
 * @return array<string, mixed>
 */
function buildCliCommand(string $name, string $part, string $url, ?string $pageSince): array
{
	$description = extractDescription($part);
	$examples = extractCodeBlocks($part);

	// Extract options table (rows where first column starts with --)
	$options = [];
	if (preg_match_all('/\|\s*`(--[^`]+)`\s*\|\s*([^|]+)\|/m', $part, $optMatches, PREG_SET_ORDER)) {
		foreach ($optMatches as $om) {
			$options[] = [
				'name'        => trim($om[1]),
				'description' => trim($om[2]),
			];
		}
	}

	// Extract arguments table (rows with Required Yes/No column)
	$arguments = [];
	if (preg_match_all('/\|\s*`(\w+)`\s*\|\s*(Yes|No)\s*\|\s*([^|]+)\|/m', $part, $argMatches, PREG_SET_ORDER)) {
		foreach ($argMatches as $am) {
			$arguments[] = [
				'name'        => trim($am[1]),
				'required'    => strtolower(trim($am[2])) === 'yes',
				'description' => trim($am[3]),
			];
		}
	}

	$cmd = [
		'name'        => $name,
		'description' => $description,
		'arguments'   => $arguments,
		'options'     => $options,
		'examples'    => $examples,
		'url'         => $url,
	];

	if ($pageSince !== null) {
		$cmd['since'] = $pageSince;
	}

	return $cmd;
}

/**
 * Parse CLI commands from a docs page.
 *
 * Command headings live at different levels depending on the page: this is
 * a rendering/authoring choice for the docs viewer, not something the
 * generator gets to dictate, so it adapts to both shapes rather than
 * requiring the docs to conform to one heading level.
 *
 * - extensions/cli.md groups commands under prose H2 section headers
 *   ("## Schema Commands") with each command as its own flat H3
 *   (`### `schema:list``, options/arguments tables directly inside it).
 * - site-builder/cli.md has only four commands and promotes each straight
 *   to H2 (`## `builder:init``), with subsections (Arguments, Options,
 *   `--force` Behavior, …) nested as H3s underneath it.
 *
 * Walk H2 sections first. A section whose heading is a bare command-shaped
 * token (see matchCliCommandName()) is extracted as ONE command spanning
 * its entire block — including everything nested under it, however many H3
 * levels down, so Options/Arguments tables buried under site-builder/cli.md's
 * structure are still found. A section whose heading ISN'T a command (prose
 * group headers, or the H1-title/intro text before the first H2) falls back
 * to an H3-level scan within that section — this is exactly the original
 * (pre-H2-awareness) algorithm, scoped to one H2 section instead of the
 * whole document, so extensions/cli.md's output is unchanged.
 *
 * @return array<int, array<string, mixed>>
 */
function parseCliCommands(string $content, string $url = 'https://docs.totalcms.co/extensions/cli/'): array
{
	$commands = [];
	$frontmatter = parseFrontmatter($content);
	$pageSince = $frontmatter['since'] ?? null;
	$body = removeFrontmatter($content);

	$h2Parts = preg_split('/^##\s+/m', $body);

	foreach ($h2Parts as $h2Part) {
		$h2Part = trim($h2Part);
		if ($h2Part === '') {
			continue;
		}

		$h2HeadingLine = (string) strtok($h2Part, "\n");
		$h2Name = matchCliCommandName($h2HeadingLine);

		if ($h2Name !== null) {
			// Whole H2 block IS the command — nested H3s (Arguments, Options,
			// `--force` Behavior, …) are content, not separate commands.
			$commands[] = buildCliCommand($h2Name, $h2Part, $url, $pageSince);
			continue;
		}

		// Not a command heading (prose group header, or the pre-first-H2
		// H1-title/intro chunk) — fall back to scanning H3 headings inside it.
		$h3Parts = preg_split('/^###\s+/m', $h2Part);
		foreach ($h3Parts as $h3Part) {
			$h3Part = trim($h3Part);
			if ($h3Part === '') {
				continue;
			}

			$h3HeadingLine = (string) strtok($h3Part, "\n");
			$h3Name = matchCliCommandName($h3HeadingLine);
			if ($h3Name === null) {
				continue;
			}

			$commands[] = buildCliCommand($h3Name, $h3Part, $url, $pageSince);
		}
	}

	return $commands;
}

/**
 * Build a structured reference of the extension API.
 * This is hand-maintained (not parsed from docs) since it represents the code contract.
 *
 * Reflectors live in bin/lib/docs-reference-reflectors.php, loaded by the
 * caller (bin/build-docs-index.php) alongside this file.
 *
 * @return array<string, mixed>
 */
function buildExtensionApiReference(): array
{
	// Auto-derive the contract surface from T3 source so new ExtensionContext
	// methods, capability labels, manifest fields, editions, bundled extensions,
	// and content events show up without anyone touching this file.
	$contextMethods    = reflectContextMethods();
	$capabilityLabels  = reflectCapabilityLabels();
	$manifestFields    = reflectManifestFields();
	$editions          = reflectEditions();
	$bundledExtensions = reflectBundledExtensions();
	$events            = parseEventsFromDocs();

	// Per-bundled-extension detail that isn't in the extension.json manifest
	// (page_feature name, page.data keys, cookies, headers, limitations).
	// Keyed by extension id so new bundled extensions show up via reflection
	// even before someone adds an entry here.
	$bundledDetail = [
		'totalcms/ab-split' => [
			'page_feature'   => 'ab-split',
			'page_data_keys' => [
				['key' => 'abTemplate', 'type' => 'string', 'description' => 'Path to variant-B template (e.g. pages/contact-b.twig). Required — empty or missing means the middleware no-ops and the page renders normally.'],
				['key' => 'abPercent',  'type' => 'int',    'default' => 50, 'description' => 'Percentage of visitors sent to variant B. Clamped to 0-100. Use 100 to force every visitor onto B for validation; 0 effectively disables the split.'],
			],
			'cookie'      => ['name' => 'tcms_ab_<page-id>', 'value' => 'a or b', 'ttl' => '30 days', 'path' => '/', 'samesite' => 'Lax'],
			'limitations' => ['Two variants only (A/B, no multivariate)', 'Builder pages only — collection-URL matches not supported', 'No built-in analytics — read the cookie client-side and tag your analytics events'],
		],
		'totalcms/geo-redirect' => [
			'page_feature'   => 'geo-redirect',
			'page_data_keys' => [
				['key' => 'geoRedirects', 'type' => 'object', 'description' => 'Map of ISO 3166-1 alpha-2 country code (e.g. "DE", "FR") to target URL. Use "*" as wildcard fallback for unlisted countries. Targets can be paths, absolute URLs, or include query strings.'],
			],
			'country_headers'  => ['CF-IPCountry (Cloudflare)', 'X-Country-Code (generic / DIY proxies)', 'X-Vercel-IP-Country (Vercel)'],
			'response_headers' => ['Vary: CF-IPCountry, X-Country-Code, X-Vercel-IP-Country'],
			'limitations'      => ['Country-level only, no city/region precision', 'Not a strong access-control mechanism — bypassable with VPN or header manipulation', 'Builder pages only — collection-URL matches not supported'],
		],
	];
	$bundledExtensions = array_map(
		fn (array $b): array => array_merge($b, $bundledDetail[$b['id']] ?? []),
		$bundledExtensions,
	);

	// The reflected label ("Twig Functions") is admin-UI friendly. AI agents
	// want a verb-leading description. This table augments where we have one;
	// new permissions appear with the short label as a graceful fallback.
	$permissionDescriptions = [
		'twig:functions'  => 'Register custom Twig functions',
		'twig:filters'    => 'Register custom Twig filters',
		'twig:globals'    => 'Register Twig global variables',
		'routes:api'      => 'Register authenticated API endpoints',
		'routes:admin'    => 'Register admin pages',
		'routes:public'   => 'Register unauthenticated public endpoints',
		'cli:commands'    => 'Register CLI commands',
		'admin:nav'       => 'Add items to admin navigation',
		'admin:widgets'   => 'Add dashboard widgets',
		'admin:assets'    => 'Load CSS/JS in the admin interface',
		'frontend:assets' => 'Load CSS/JS on public pages',
		'events:listen'   => 'Subscribe to content events',
		'fields'          => 'Register custom field types',
		'schemas'         => 'Install user-editable schemas (Pro+ only)',
		'container'       => 'Register DI container services',
		'page-middleware' => 'Register page-features middleware (since 3.5.0)',
	];
	$permissions = array_map(
		fn (array $p): array => [
			'id'          => $p['id'],
			'description' => $permissionDescriptions[$p['id']] ?? $p['description'],
		],
		$capabilityLabels,
	);

	return [
		'min_version' => '3.3.0',
		'note' => 'The extension system requires Total CMS 3.3.0 or later. It is not available in earlier versions.',
		'context_methods' => $contextMethods,
		'events' => $events,
		'permissions' => $permissions,
		'manifest_fields' => $manifestFields,
		'editions'        => $editions,
		'starter_repo'    => 'https://github.com/totalcms/extension-starter',
		'bundled_extensions' => [
			'since'         => '3.5.0',
			'note'          => 'Bundled extensions ship in the T3 package itself under resources/extensions/. They install automatically (no composer require, no upload), are disabled by default, and cannot be removed — only enabled/disabled. The manifest version field is ignored; they always report the running T3 version. A user-installed extension with the same ID under tcms-data/extensions/ shadows the bundled copy.',
			'install_path'  => 'resources/extensions/{vendor}/{name}/',
			'override_path' => 'tcms-data/extensions/{vendor}/{name}/ (user-installed copy wins on ID collision)',
			'items'         => $bundledExtensions,
			'cli' => [
				'tcms extension:list',
				'tcms extension:enable totalcms/<name>',
				'tcms extension:disable totalcms/<name>',
				'tcms extension:remove totalcms/<name>  # refuses with a friendly error pointing at disable',
			],
			'docs' => array_merge(
				[['label' => 'Bundled Extensions Overview', 'url' => 'https://docs.totalcms.co/extensions/bundled/']],
				array_map(
					fn (array $b): array => ['label' => $b['name'], 'url' => $b['url']],
					$bundledExtensions,
				),
			),
		],
		'url' => 'https://docs.totalcms.co/extensions/overview/',
	];
}

/**
 * Build a structured reference of the Site Builder.
 * Hand-maintained — represents the user-facing contract for building sites.
 *
 * @return array<string, mixed>
 */
function buildBuilderApiReference(): array
{
	return [
		'min_version' => '3.3.0',
		'note' => 'The Site Builder requires Total CMS 3.3.0 or later. Pages are routed dynamically from the builder-pages collection — there is no generation or deployment step.',
		'overview' => 'The Site Builder lets you build a complete frontend website inside Total CMS. Pages are collection objects with URL routes and templates. A middleware-based router (PageRouterMiddleware) matches incoming URLs against page routes and renders templates dynamically. API routes (/api/*) and admin routes (/admin/*) take priority.',
		'pages_collection' => 'builder-pages',
		'page_schema' => [
			'id'          => 'builder-page',
			'description' => 'Schema used by every object in the builder-pages collection.',
			'fields' => [
				['name' => 'id',          'type' => 'slug',     'description' => 'Page identifier (auto-generated from title)'],
				['name' => 'title',       'type' => 'text',     'description' => 'Page title'],
				['name' => 'route',       'type' => 'text',     'description' => 'URL pattern. Static (e.g. "/about") or dynamic with {param} placeholders (e.g. "/products/{id}")'],
				['name' => 'template',    'type' => 'text',     'description' => 'Page template name from tcms-data/builder/pages/'],
				['name' => 'layout',      'type' => 'select',   'description' => 'Layout template from tcms-data/builder/layouts/ (extended via {% extends %})'],
				['name' => 'description', 'type' => 'textarea', 'description' => 'Meta description'],
				['name' => 'draft',       'type' => 'toggle',   'description' => 'When true, the page is excluded from routing entirely (cannot be visited)'],
				['name' => 'nav',         'type' => 'toggle',   'description' => 'Include in nav menus. Defaults to true. Distinct from draft — a published page can be hidden from menus.'],
				['name' => 'sort',        'type' => 'number',   'description' => 'Navigation sort order (lower = first)'],
				['name' => 'parent',      'type' => 'select',   'description' => 'Parent page ID for hierarchical menus and subnav()'],
				['name' => 'middleware',  'type' => 'multicheckbox', 'description' => 'Page features (middleware) to run before render. Picked from a registry of installed middleware names — admin sees this field as "Features". Built-in: auth. Extensions register more via addPageMiddleware(). Since 3.5.0.'],
				['name' => 'accessGroups','type' => 'multiselect',   'description' => 'When auth feature is enabled, restricts access to users in any of the listed groups (SuperAdmins always pass). Empty = any logged-in user passes.'],
				['name' => 'data',        'type' => 'json',     'description' => 'Free-form per-page configuration consumed by page features and templates (exposed as page.data.* in Twig).'],
			],
		],
		'directory_structure' => [
			['path' => 'tcms-data/builder/layouts/',  'description' => 'Base HTML layouts. Page templates extend these via {% extends "layouts/<name>.twig" %}.'],
			['path' => 'tcms-data/builder/pages/',    'description' => 'Page content templates. Multiple page objects can share the same template.'],
			['path' => 'tcms-data/builder/partials/', 'description' => 'Reusable fragments (nav, footer, cards). Included via {% include %}.'],
			['path' => 'tcms-data/builder/macros/',   'description' => 'Reusable Twig macros for repeated rendering patterns.'],
		],
		'template_data' => [
			['name' => 'page',   'type' => 'object', 'description' => 'The full page object (id, title, route, template, layout, description, sort, parent, etc.)'],
			['name' => 'params', 'type' => 'object', 'description' => 'URL parameters extracted from dynamic routes. For route /products/{id}, params.id holds the captured segment.'],
		],
		'twig_functions' => [
			['name' => 'cms.builder.nav',     'signature' => 'nav(string $collection = "builder-pages"): array',                  'description' => 'Top-level navigation pages (no parent). Auto-filters drafts and nav:false pages, sorted by sort field.'],
			['name' => 'cms.builder.subnav',  'signature' => 'subnav(string $parentId, string $collection = "builder-pages"): array', 'description' => 'Child pages of a specific parent.'],
			['name' => 'cms.builder.navTree', 'signature' => 'navTree(string $collection = "builder-pages"): array',              'description' => 'Full hierarchy as a nested tree. Each item gets a children array, recursively.'],
			['name' => 'cms.builder.asset',   'signature' => 'asset(string $path): string',                                       'description' => 'Resolve an asset path to a URL with cache busting (manifest hash if present, else ?v=mtime).'],
			['name' => 'cms.builder.css',     'signature' => 'css(string $path): string',                                         'description' => 'Output a <link rel="stylesheet"> tag for a CSS file.'],
			['name' => 'cms.builder.js',      'signature' => 'js(string $path, array $options = []): string',                     'description' => 'Output a <script> tag. Pass {module: true} to add type="module".'],
			['name' => 'cms.builder.preload', 'signature' => 'preload(string $path, string $as): string',                         'description' => 'Output a <link rel="preload"> tag. Auto-adds crossorigin for fonts. $as is one of: font, image, script, style, fetch.'],
		],
		'cli_commands' => [
			['name' => 'builder:init', 'signature' => 'tcms builder:init [starter] [--list] [--force] [--json]', 'description' => 'Scaffold a new site from a bundled starter template. Copies templates into tcms-data/builder/, creates the builder-pages collection, and seeds page objects from the starter manifest.'],
		],
		'starters' => [
			['id' => 'minimal',   'pages' => ['Home'],                                            'description' => 'Single page with clean layout'],
			['id' => 'business',  'pages' => ['Home', 'About', 'Services', 'Contact'],            'description' => 'Professional business site'],
			['id' => 'blog',      'pages' => ['Home', 'Blog', 'Blog Post', 'About'],              'description' => 'Blog-focused site with dynamic post routing'],
			['id' => 'portfolio', 'pages' => ['Home', 'Work', 'About', 'Contact'],                'description' => 'Portfolio site with project cards'],
		],
		'asset_config' => [
			'assets_path' => [
				'description' => 'Public assets directory relative to docroot. Configured in Admin > Settings > Builder.',
				'default'     => 'assets',
			],
			'manifest' => [
				'description' => 'For production builds with content-hashed filenames, output a manifest.json into the assets directory. Asset functions automatically resolve hashed filenames from the manifest. Without a manifest, asset URLs use ?v={mtime} for cache busting.',
				'file'        => 'manifest.json',
				'tools'       => ['vite', 'esbuild'],
			],
		],
		'route_patterns' => [
			['pattern' => '/about',                'type' => 'static',  'description' => 'Exact match. Visiting /about renders this page.'],
			['pattern' => '/products/{id}',        'type' => 'dynamic', 'description' => 'Single segment captured into params.id.'],
			['pattern' => '/blog/{category}/{slug}', 'type' => 'dynamic', 'description' => 'Multiple segments captured into params.category and params.slug.'],
			['pattern' => '/robots.txt',           'type' => 'file',    'description' => 'File-extension routes auto-pick the right Content-Type. Supports .txt, .xml, .rss, .json, .md, .css, .js, .csv, .svg.'],
		],
		'collection_url_routing' => [
			'description' => 'When a collection has a url field set (e.g., /blog with pretty URLs enabled), the middleware also matches collection object URLs. /blog/my-post → fetches the my-post object from blog and renders templates/blog.twig with the object as page.',
			'pairing'     => 'A common pattern is a builder list page (/blog → blog-index template) plus a builder detail page (/blog/{id} → blog-post template) with the collection URL set to /blog so objectUrl() generates matching URLs.',
		],
		'page_features' => [
			'since'       => '3.5.0',
			'description' => 'Named middleware that builder pages opt into via the page form\'s Features field (stored in the page\'s middleware field). Each runs before the template renders and can short-circuit the request with a response (auth redirect, 302, 429, etc.) or pass through. Features run in the order listed on the page; the first one to return a response wins.',
			'admin_field' => 'Features (multicheckbox, stored as the page object\'s middleware field)',
			'config_via'  => 'Per-page configuration goes in the page\'s data (JSON) field. Each feature reads its own keys from page.data.* — see individual feature docs.',
			'built_in' => [
				[
					'name'        => 'auth',
					'description' => 'Requires a logged-in visitor. Logged-out browsers get 302 redirected to /admin/login (with ?redirect= back). JSON requests get 401 {"error": "Authentication required"}. Optionally scoped to specific access groups via the page\'s accessGroups field — non-members get 403 (no login redirect since they\'re already in). SuperAdmins always pass.',
					'page_fields' => ['accessGroups'],
				],
			],
			'bundled_features' => [
				['name' => 'ab-split',     'extension' => 'totalcms/ab-split',     'since' => '3.5.0', 'description' => 'Render an alternate template for a percentage of visitors. Sticky via 30-day cookie. Configure via page.data.abTemplate and page.data.abPercent.', 'url' => 'https://docs.totalcms.co/extensions/ab-split/'],
				['name' => 'geo-redirect', 'extension' => 'totalcms/geo-redirect', 'since' => '3.5.0', 'description' => '302 visitors based on country detected from CDN-injected headers (Cloudflare, Vercel, generic). Configure via page.data.geoRedirects.', 'url' => 'https://docs.totalcms.co/extensions/geo-redirect/'],
			],
			'registering_from_extension' => [
				'method'          => '$context->addPageMiddleware(string $name, string $middlewareClass): void',
				'permission'      => 'page-middleware',
				'naming_rules'    => 'Lowercase letters, digits, and hyphens only (e.g. geo-redirect, rate-limit, staff-only). Names are a stable contract — once shipped, a rename breaks pages that reference them.',
				'class_contract'  => 'Must implement TotalCMS\\Domain\\Builder\\PageMiddleware\\PageMiddlewareInterface — handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface. Return null to proceed (let next middleware / page render); return a Response to short-circuit.',
			],
			'failure_modes' => [
				'Unknown name in a page\'s middleware list (typo or uninstalled extension): runner logs a warning, silently skips that name, chain continues — page still renders.',
				'Middleware throws an exception: runner returns a 500 response (fail-closed for security so auth/geo gates never silently let pages through).',
			],
			'scope' => 'Per-page middleware applies only to builder-page route matches. Collection-URL matches (e.g. /blog/my-post resolved against a collection\'s url field) do NOT currently support per-record middleware — apply your own auth/etc in the collection\'s template.',
		],
		'docs' => [
			['label' => 'Site Builder Overview',         'url' => 'https://docs.totalcms.co/site-builder/overview/'],
			['label' => 'Page Features (Middleware)',    'url' => 'https://docs.totalcms.co/site-builder/overview/#page-features-middleware'],
			['label' => 'Builder Admin UI',              'url' => 'https://docs.totalcms.co/site-builder/admin/'],
			['label' => 'Frontend Assets (Vite)',        'url' => 'https://docs.totalcms.co/site-builder/frontend/'],
			['label' => 'Builder CLI Commands',          'url' => 'https://docs.totalcms.co/site-builder/cli/'],
			['label' => 'Starter Templates',             'url' => 'https://docs.totalcms.co/site-builder/starters/'],
			['label' => 'Builder Twig Reference',        'url' => 'https://docs.totalcms.co/site-builder/twig/'],
			['label' => 'Bundled Extensions',            'url' => 'https://docs.totalcms.co/extensions/bundled/'],
		],
		'url' => 'https://docs.totalcms.co/site-builder/overview/',
	];
}

/**
 * Minimum row counts the built index must have for each top-level section.
 *
 * Thresholds are deliberately set well below the current actuals so routine
 * doc edits don't trip the check. They're tight enough to catch obvious
 * breakage: a broken parser silently dropping entries, or building against a
 * docs tree that predates a whole section.
 *
 * Update these when the corresponding docs sections grow meaningfully.
 */
const REFERENCE_INDEX_MINIMUM_COUNTS = [
	'twig_functions' => 180,
	'twig_filters'   => 50,
	'field_types'    => 15,
	'api_endpoints'  => 20,
	'schema_config'  => 20,
	'cli_commands'   => 20,
];

/**
 * Validate that the built index has at least the minimum expected rows in
 * each major section. Returns an array of human-readable failure messages
 * (empty if the index passes all checks).
 *
 * @param array<string, mixed>    $index
 * @param array<string, int>|null $minimums Override defaults — used by tests
 * @return string[]
 */
function validateIndexCounts(array $index, ?array $minimums = null): array
{
	$thresholds = $minimums ?? REFERENCE_INDEX_MINIMUM_COUNTS;
	$failures = [];

	foreach ($thresholds as $key => $minimum) {
		$actual = is_array($index[$key] ?? null) ? count($index[$key]) : 0;
		if ($actual < $minimum) {
			$failures[] = sprintf('%s: %d (expected >= %d)', $key, $actual, $minimum);
		}
	}

	return $failures;
}

/**
 * Top-level docs.totalcms.co URL prefixes that map to real folders in
 * resources/docs/. Any other prefix in the built index indicates a stale
 * path (a folder rename that a hardcoded URL in a parser missed, or a typo).
 *
 * Keep this list in lockstep with the top-level folders in resources/docs/.
 */
const ALLOWED_DOCS_URL_PREFIXES = [
	'admin', 'apis', 'auth', 'automations', 'collections', 'extensions', 'fields', 'forms',
	'get-started', 'mcp', 'notifications', 'operations', 'schemas', 'site-builder', 'twig',
];

/**
 * Recursively scan the built index for `url` fields and flag any whose
 * top-level docs.totalcms.co prefix isn't a real resources/docs/ folder.
 * Catches folder renames like `/builder/` → `/site-builder/`.
 *
 * (The old implementation also cross-checked full slugs against an indexed
 * `pages` array; this index doesn't carry `pages` — see the module docblock
 * — so only the top-level-prefix check runs.)
 *
 * @param array<string, mixed> $index
 * @return string[]
 */
function validateIndexUrls(array $index): array
{
	$failures = [];
	$allowed = array_flip(ALLOWED_DOCS_URL_PREFIXES);

	$walk = function ($node, string $path) use (&$walk, &$failures, $allowed): void {
		if (!is_array($node)) {
			return;
		}
		foreach ($node as $key => $value) {
			$childPath = $path === '' ? (string) $key : "$path.$key";
			if ($key === 'url' && is_string($value)) {
				if (preg_match('#https?://docs\.totalcms\.co/([^/]+)/#', $value, $m)) {
					if (!isset($allowed[$m[1]])) {
						$failures[] = "$childPath: $value (stale top-level prefix)";
					}
				}
			} elseif (is_array($value)) {
				$walk($value, $childPath);
			}
		}
	};

	$walk($index, '');
	return $failures;
}

/**
 * Files under twig/ AND under other top-level sections that contain documented
 * `cms.<namespace>.*` function signatures. Used by assembleReferenceIndex to
 * harvest doc-side function bodies for merging with reflection output.
 */
const TWIG_NAMESPACE_DOC_FILES = [
	'twig/collections.md', 'twig/data.md', 'twig/media.md', 'twig/imageworks.md',
	'twig/variables.md', 'twig/totalcms.md', 'twig/render.md', 'twig/views.md',
	'twig/locale.md', 'twig/localization.md', 'twig/load-more.md', 'twig/utils.md',
	'auth/twig.md', 'admin/twig.md', 'schemas/twig.md', 'site-builder/twig.md',
	'forms/overview.md', 'forms/builder.md', 'forms/deck.md', 'forms/fields.md',
	'forms/options.md', 'forms/patterns.md', 'forms/report.md', 'forms/specialized.md',
];

/**
 * Build the docs-only portion of the reference index from a docs directory.
 *
 * Does NOT include `extension_api` or `builder_api` — those require reflecting
 * against PHP source files and are layered on top by the caller
 * (bin/build-docs-index.php). Likewise, the `twig_functions` returned here
 * contains only the standalone (non-namespace) helpers parsed from
 * functions.md; caller merges with reflected cms.* functions.
 *
 * Pure function: takes a docs directory, returns an array. Safe to call from
 * tests against a fixture tree.
 *
 * @return array{
 *   twig_filters: array<int, array<string, mixed>>,
 *   twig_functions: array<int, array<string, mixed>>,
 *   documented_namespace_functions: array<int, array<string, mixed>>,
 *   field_types: array<int, array<string, mixed>>,
 *   api_endpoints: array<int, array<string, mixed>>,
 *   schema_config: array<int, array<string, mixed>>,
 *   cli_commands: array<int, array<string, mixed>>
 * }
 */
function assembleReferenceIndex(string $docsDir): array
{
	// Twig filters
	$twigFilters = [];
	$filtersFile = $docsDir . '/twig/filters.md';
	if (is_file($filtersFile)) {
		$twigFilters = parseFilterSignatures((string) file_get_contents($filtersFile));
	}

	// Standalone (non-cms.*) Twig functions
	$twigFunctions = [];
	$functionsFile = $docsDir . '/twig/functions.md';
	if (is_file($functionsFile)) {
		$twigFunctions = parseFunctionSignatures((string) file_get_contents($functionsFile));
	}

	// Documented cms.* namespace functions (caller merges with reflection)
	$documentedNamespaceFns = [];
	foreach (TWIG_NAMESPACE_DOC_FILES as $relPath) {
		$filePath = $docsDir . '/' . $relPath;
		if (is_file($filePath)) {
			$documentedNamespaceFns = array_merge(
				$documentedNamespaceFns,
				parseNamespaceFunctions((string) file_get_contents($filePath), $relPath),
			);
		}
	}

	// Field types — fields/*.md excluding -options.md (those are Field Options)
	$fieldTypes = [];
	$fieldsDir = $docsDir . '/fields';
	if (is_dir($fieldsDir)) {
		foreach (glob($fieldsDir . '/*.md') ?: [] as $propFile) {
			$baseName = basename($propFile);
			if (str_ends_with($baseName, '-options.md')) {
				continue;
			}
			$content = (string) file_get_contents($propFile);
			$frontmatter = parseFrontmatter($content);
			$fieldTypes[] = [
				'name'        => str_replace('.md', '', $baseName),
				'title'       => $frontmatter['title'] ?? basename($propFile, '.md'),
				'description' => $frontmatter['description'] ?? '',
				'content'     => cleanForSearch(removeFrontmatter($content)),
				'url'         => 'https://docs.totalcms.co/fields/' . str_replace('.md', '/', $baseName),
			];
		}
	}
	// Schema types live in the same field_types bucket so docs_field_type can
	// surface "blog", "image", "gallery", etc.
	$schemasDir = $docsDir . '/schemas';
	if (is_dir($schemasDir)) {
		foreach (glob($schemasDir . '/*.md') ?: [] as $schemaFile) {
			$content = (string) file_get_contents($schemaFile);
			$frontmatter = parseFrontmatter($content);
			$fieldTypes[] = [
				'name'        => str_replace('.md', '', basename($schemaFile)),
				'title'       => $frontmatter['title'] ?? basename($schemaFile, '.md'),
				'description' => $frontmatter['description'] ?? '',
				'content'     => cleanForSearch(removeFrontmatter($content)),
				'url'         => 'https://docs.totalcms.co/schemas/' . str_replace('.md', '/', basename($schemaFile)),
			];
		}
	}

	// API endpoints
	$apiEndpoints = [];
	$apiFile = $docsDir . '/apis/rest-api.md';
	if (is_file($apiFile)) {
		$apiEndpoints = parseApiEndpoints((string) file_get_contents($apiFile));
	}
	$indexFilterFile = $docsDir . '/apis/index-filter.md';
	if (is_file($indexFilterFile)) {
		$apiEndpoints = array_merge($apiEndpoints, parseApiEndpoints((string) file_get_contents($indexFilterFile)));
	}

	// Schema/collection config
	$schemaConfig = [];
	$settingsFile = $docsDir . '/collections/settings.md';
	if (is_file($settingsFile)) {
		$schemaConfig = parseSchemaConfig((string) file_get_contents($settingsFile));
	}
	$schemaRefFile = $docsDir . '/schemas/reference.md';
	if (is_file($schemaRefFile)) {
		$schemaRefConfigs = parseSchemaConfig((string) file_get_contents($schemaRefFile));
		foreach ($schemaRefConfigs as &$cfg) {
			$cfg['url'] = 'https://docs.totalcms.co/schemas/reference/';
		}
		unset($cfg);
		$schemaConfig = array_merge($schemaConfig, $schemaRefConfigs);
	}

	// CLI commands
	$cliCommands = [];
	foreach (['extensions/cli.md', 'site-builder/cli.md'] as $relPath) {
		$filePath = $docsDir . '/' . $relPath;
		if (!is_file($filePath)) {
			continue;
		}
		$pageUrl = 'https://docs.totalcms.co/' . str_replace('.md', '/', $relPath);
		$cliCommands = array_merge(
			$cliCommands,
			parseCliCommands((string) file_get_contents($filePath), $pageUrl),
		);
	}

	return [
		'twig_filters'                   => $twigFilters,
		'twig_functions'                 => $twigFunctions,
		'documented_namespace_functions' => $documentedNamespaceFns,
		'field_types'                    => $fieldTypes,
		'api_endpoints'                  => $apiEndpoints,
		'schema_config'                  => $schemaConfig,
		'cli_commands'                   => $cliCommands,
	];
}
