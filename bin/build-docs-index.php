#!/usr/bin/env php
<?php

/**
 * Build a search index for documentation files.
 *
 * This script reads all markdown files in resources/docs/ and generates
 * a JSON search index that can be used for client-side full-text search.
 *
 * Run: php bin/build-docs-index.php
 * Output: resources/docs/search-index.json
 */
$docsDir    = __DIR__ . '/../resources/docs';
$outputFile = $docsDir . '/search-index.json';

// Get all markdown files (including subdirectories)
$files = glob($docsDir . '/{,*/}*.md', GLOB_BRACE);

$index = [];

foreach ($files as $file) {
	$relativePath = str_replace($docsDir . '/', '', $file);
	$path         = str_replace('.md', '', $relativePath);

	// Skip the index file itself
	if ($path === 'index') {
		continue;
	}

	$content = file_get_contents($file);

	// Extract title from first H1
	$title = basename($file, '.md');
	if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
		$title = trim($matches[1]);
	}

	// Extract all headings for section indexing
	$sections = [];
	if (preg_match_all('/^##\s+(.+)$/m', $content, $matches)) {
		$sections = $matches[1];
	}

	// Clean content for indexing:
	// - Remove code block fences but keep the code content
	$searchContent = preg_replace('/```\w*\n?/', '', $content);
	// - Replace <pre> tags with their content (preserve code for searching)
	$searchContent = preg_replace('/<pre[^>]*>(.*?)<\/pre>/s', ' $1 ', $searchContent);
	// - Remove HTML tags
	$searchContent = strip_tags($searchContent);
	// - Remove markdown formatting but preserve dots (for cms.login, etc.)
	$searchContent = preg_replace('/[#*_`\[\]()]/', ' ', $searchContent);
	// - Remove URLs
	$searchContent = preg_replace('/https?:\/\/[^\s]+/', '', $searchContent);
	// - Remove Twig delimiters but keep the content
	$searchContent = preg_replace('/\{\{|\}\}|\{%|%\}/', ' ', $searchContent);
	// - Normalize whitespace
	$searchContent = preg_replace('/\s+/', ' ', $searchContent);
	$searchContent = trim($searchContent);

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
