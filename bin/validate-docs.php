#!/usr/bin/env php
<?php

/**
 * Validate the integrity of resources/docs/.
 *
 * Checks:
 *   1. Every internal link `(docs/<section>/<page>)` resolves to an existing .md file.
 *   2. Every image ref `(docs/<section>/images/<file>)` resolves to an existing image.
 *   3. Every menu.php entry's `path` resolves to an existing .md file.
 *   4. Every .md file is reachable from menu.php or linked from another doc page
 *      (orphan detection — catches pages that exist on disk but no nav points to them).
 *
 * Run via:  composer run docs:validate
 *           php bin/validate-docs.php
 *
 * Exits 0 on clean, 1 on any failures.
 */

declare(strict_types=1);

const DOCS_DIR = __DIR__ . '/../resources/docs';
const MENU_FILE = DOCS_DIR . '/menu.php';

$docsDir = realpath(DOCS_DIR);
if ($docsDir === false || !is_dir($docsDir)) {
	fwrite(STDERR, "Docs directory not found at " . DOCS_DIR . "\n");
	exit(1);
}

$failures = [];

// Set of doc slugs (relative path without .md) that have been "reached" by
// either menu.php or an inbound link from another .md. Used for orphan
// detection at the end.
$reached = [];

// ---------------------------------------------------------------
// 1+2. Walk every .md file and verify internal/image refs resolve.
// ---------------------------------------------------------------
$mdFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $entry) {
	if (!$entry->isFile() || $entry->getExtension() !== 'md') {
		continue;
	}
	// Skip internal/ — not shipped, not synced
	$rel = ltrim(str_replace($docsDir, '', $entry->getPathname()), '/');
	if (str_starts_with($rel, 'internal/')) {
		continue;
	}
	$mdFiles[] = $entry->getPathname();
}

$imageExts = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
foreach ($mdFiles as $file) {
	$rel = ltrim(str_replace($docsDir, '', $file), '/');
	$content = file_get_contents($file);

	// Match (docs/<anything-not-paren-or-quote>) in markdown link/image parens.
	if (!preg_match_all('/\(docs\/([^)"\s#]+)(#[^)"\s]*)?\)/', $content, $matches, PREG_SET_ORDER)) {
		continue;
	}

	foreach ($matches as $m) {
		$target = $m[1];
		$ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

		if (in_array($ext, $imageExts, true)) {
			$resolved = $docsDir . '/' . $target;
			if (!is_file($resolved)) {
				$failures[] = "[image] $rel → docs/$target (not found)";
			}
			continue;
		}

		// Page link — should resolve to <target>.md
		$resolved = $docsDir . '/' . $target . '.md';
		if (!is_file($resolved)) {
			$failures[] = "[link]  $rel → docs/$target (no $target.md)";
			continue;
		}
		$reached[$target] = true;
	}
}

// ---------------------------------------------------------------
// 3. Walk menu.php and verify every path resolves.
// ---------------------------------------------------------------
if (!is_file(MENU_FILE)) {
	$failures[] = "[menu]  menu.php not found at " . MENU_FILE;
} else {
	$menu = require MENU_FILE;
	if (!is_array($menu)) {
		$failures[] = "[menu]  menu.php did not return an array";
	} else {
		$checkEntries = function (array $entries, string $context) use ($docsDir, &$failures, &$reached): void {
			foreach ($entries as $entry) {
				if (!isset($entry['path'])) {
					$failures[] = "[menu]  $context: entry missing 'path'";
					continue;
				}
				// AdminDocsAction serves both .md and .html for menu entries
				// (openapi.html is the live API reference iframe), so accept either.
				$base = $docsDir . '/' . $entry['path'];
				if (!is_file("$base.md") && !is_file("$base.html")) {
					$failures[] = "[menu]  $context: {$entry['path']} (no .md or .html)";
					continue;
				}
				$reached[$entry['path']] = true;
			}
		};
		foreach ($menu as $group) {
			$gTitle = $group['title'] ?? '(untitled)';
			if (!empty($group['sub'])) {
				$checkEntries($group['sub'], $gTitle);
			}
			if (!empty($group['groups'])) {
				foreach ($group['groups'] as $sub) {
					$checkEntries($sub['sub'] ?? [], $gTitle . ' / ' . ($sub['title'] ?? '(untitled)'));
				}
			}
		}
	}
}

// ---------------------------------------------------------------
// 4. Orphan detection — every .md file should be reachable from menu.php
//    or linked from another doc page. index.md is the homepage and skipped.
// ---------------------------------------------------------------
foreach ($mdFiles as $file) {
	$slug = ltrim(str_replace([$docsDir, '.md'], '', $file), '/');
	if ($slug === 'index') {
		continue;
	}
	if (!isset($reached[$slug])) {
		$failures[] = "[orphan] $slug.md (not referenced by menu.php or any other doc)";
	}
}

// ---------------------------------------------------------------
// Report
// ---------------------------------------------------------------
$pageCount = count($mdFiles);
if ($failures === []) {
	echo "OK: scanned $pageCount markdown files, all links and menu entries resolve, no orphans.\n";
	exit(0);
}

fwrite(STDERR, "FAIL: " . count($failures) . " broken references in $pageCount markdown files:\n\n");
foreach ($failures as $line) {
	fwrite(STDERR, "  $line\n");
}
exit(1);
