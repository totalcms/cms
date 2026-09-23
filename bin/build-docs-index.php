#!/usr/bin/env php
<?php

/**
 * Build the documentation search index AND the reference-lookup index.
 *
 * This script reads all markdown files in resources/docs/ and generates:
 * - search-index.json: full-text search index (client-side + docs_search MCP tool)
 * - reference-index.json: structured lookup index (twig functions/filters, field
 *   types, API endpoints, schema config, CLI commands, extension API, builder API)
 *   consumed by the docs_lookup MCP tool
 *
 * Run: php bin/build-docs-index.php
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/docs-reference-parsers.php';
require_once __DIR__ . '/lib/docs-reference-reflectors.php';

use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;
use TotalCMS\Domain\Docs\Service\DocsPageLoader;

$docsDir            = __DIR__ . '/../resources/docs';
$outputFile         = $docsDir . '/search-index.json';
$referenceIndexFile = $docsDir . '/reference-index.json';

// The same loader and renderer the in-admin viewer uses, so the index
// carries the sidebar's group labels and indexes what the reader sees.
$loader   = new DocsPageLoader($docsDir);
$renderer = new DocsMarkdownRenderer();

$groupLookup = array_column($loader->pages(), 'group', 'path');

$index = [];

foreach ($loader->markdownPages() as $path) {
	$content = file_get_contents("{$docsDir}/{$path}.md");
	if ($content === false) {
		continue;
	}

	$page  = $renderer->render($content);
	$title = $page['title'] !== '' ? $page['title'] : basename($path);

	// H2s are the sections a search hit can point into
	$sections = array_values(array_map(
		static fn (array $heading): string => $heading['text'],
		array_filter($page['toc'], static fn (array $heading): bool => $heading['level'] === 2),
	));

	$searchContent = DocsMarkdownRenderer::searchText($page['content']);

	// Create excerpt (first 200 chars after title)
	$excerpt = substr($searchContent, 0, 200);
	if (strlen($searchContent) > 200) {
		$excerpt .= '...';
	}

	// Build keywords from headings and important terms
	$keywords = strtolower($title . ' ' . implode(' ', $sections));

	$index[] = [
		'path'     => $path,
		'title'    => $title,
		'group'    => $groupLookup[$path] ?? '',
		'sections' => $sections,
		'excerpt'  => $excerpt,
		'content'  => strtolower($searchContent),
		'keywords' => $keywords,
	];
}

// Sort by title
usort($index, fn ($a, $b) => strcasecmp($a['title'], $b['title']));

// Write the index
$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($outputFile, $json);

echo "Documentation search index built successfully!\n";
echo 'Indexed ' . count($index) . " documents.\n";
echo "Output: $outputFile\n";

// -------------------------------------------------------
// Build the reference-lookup index (resources/docs/reference-index.json).
//
// Ported from mcp.totalcms.co's build suite (see docs-reference-parsers.php
// and docs-reference-reflectors.php docblocks). Docs-only parsing supplies
// twig_filters/field_types/api_endpoints/schema_config/cli_commands and the
// documented half of the cms.* twig_functions namespace; reflection supplies
// the canonical cms.* function surface plus the extension_api section.
// builder_api is hand-maintained (represents the user-facing Site Builder
// contract, not something reflectable from a single class).
// -------------------------------------------------------
echo "\nBuilding reference index...\n";

$referenceDocs = assembleReferenceIndex($docsDir);

$reflectedNamespaceFns            = reflectCmsTwigFunctions();
[$mergedNamespaceFns, $staleDocs] = mergeTwigFunctions(
	$reflectedNamespaceFns,
	$referenceDocs['documented_namespace_functions'],
);
$twigFunctions = array_merge($referenceDocs['twig_functions'], $mergedNamespaceFns);

echo '  Twig functions: ' . count($twigFunctions)
	. ' (cms.*: ' . count($reflectedNamespaceFns) . ' reflected, '
	. count($referenceDocs['documented_namespace_functions']) . ' documented';
if ($staleDocs !== []) {
	echo ', ' . count($staleDocs) . ' doc-only — possibly stale';
}
echo ")\n";
if ($staleDocs !== [] && count($staleDocs) <= 10) {
	foreach ($staleDocs as $staleName) {
		echo "    doc-only (no matching cms.* method): {$staleName}\n";
	}
}

echo '  Twig filters: ' . count($referenceDocs['twig_filters']) . "\n";
echo '  Field types: ' . count($referenceDocs['field_types']) . "\n";
echo '  API endpoints: ' . count($referenceDocs['api_endpoints']) . "\n";
echo '  Schema config options: ' . count($referenceDocs['schema_config']) . "\n";
echo '  CLI commands: ' . count($referenceDocs['cli_commands']) . "\n";

$extensionApi = buildExtensionApiReference();
$builderApi   = buildBuilderApiReference();

// No build timestamp: nothing reads one, and it made the index the only
// generated file that changed on every rebuild — 412 KB of diff on a run that
// produced identical content. Without it a rebuild over unchanged docs is a
// no-op, so any diff here means the documentation actually changed. Git already
// records when the file was last built, more reliably than a self-reported stamp.
$referenceIndex = [
	'version'        => 1,
	'twig_functions' => $twigFunctions,
	'twig_filters'   => $referenceDocs['twig_filters'],
	'field_types'    => $referenceDocs['field_types'],
	'api_endpoints'  => $referenceDocs['api_endpoints'],
	'schema_config'  => $referenceDocs['schema_config'],
	'cli_commands'   => $referenceDocs['cli_commands'],
	'extension_api'  => $extensionApi,
	'builder_api'    => $builderApi,
];

// Refuse to write a clearly-broken index — catches a parser regression that
// silently drops a major chunk of content. See REFERENCE_INDEX_MINIMUM_COUNTS
// in docs-reference-parsers.php.
$countFailures = validateIndexCounts($referenceIndex);
if ($countFailures !== []) {
	fwrite(STDERR, "\nError: reference index failed minimum-count sanity check:\n");
	foreach ($countFailures as $msg) {
		fwrite(STDERR, "  - {$msg}\n");
	}
	fwrite(STDERR, "\nThe reference index was NOT written. Likely cause: a parser is silently\n");
	fwrite(STDERR, "dropping content, or resources/docs/ is missing an expected section.\n");
	exit(1);
}

// Also refuse to write an index with stale URL prefixes — catches hardcoded
// docs.totalcms.co URLs that point at folders that no longer exist. See
// ALLOWED_DOCS_URL_PREFIXES in docs-reference-parsers.php.
$urlFailures = validateIndexUrls($referenceIndex);
if ($urlFailures !== []) {
	fwrite(STDERR, "\nError: reference index contains URLs with stale top-level prefixes:\n");
	foreach ($urlFailures as $msg) {
		fwrite(STDERR, "  - {$msg}\n");
	}
	fwrite(STDERR, "\nThe reference index was NOT written. Update the hardcoded URL in the\n");
	fwrite(STDERR, "offending parser to use a current top-level docs folder.\n");
	exit(1);
}

$referenceJson = json_encode($referenceIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($referenceIndexFile, $referenceJson);

$referenceSizeKb = round(strlen((string)$referenceJson) / 1024);
echo "\nReference index built successfully!\n";
echo "Output: {$referenceIndexFile} ({$referenceSizeKb} KB)\n";
