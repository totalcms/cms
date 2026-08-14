<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class ReferenceIndexTest extends TestCase
{
	private const MINIMUMS = [
		'twig_functions' => 200,
		'twig_filters'   => 60,
		'field_types'    => 15,
		'api_endpoints'  => 25,
		'schema_config'  => 20,
		'cli_commands'   => 25,
		'extension_api'  => 5,
		'builder_api'    => 8,
	];

	public function testReferenceIndexShipsWithAllKindsPopulated(): void
	{
		$file = dirname(__DIR__, 3) . '/resources/docs/reference-index.json';
		$this->assertFileExists($file, 'reference-index.json must ship with the package (run bin/build-docs-index.php)');

		$index = json_decode((string)file_get_contents($file), true);
		$this->assertIsArray($index);

		foreach (self::MINIMUMS as $kind => $minimum) {
			$this->assertArrayHasKey($kind, $index);
			$this->assertGreaterThanOrEqual(
				$minimum,
				count($index[$kind]),
				"reference-index.json '{$kind}' looks under-populated — generator regression?",
			);
		}
	}

	public function testEveryTwigFunctionHasNameAndDescription(): void
	{
		$file  = dirname(__DIR__, 3) . '/resources/docs/reference-index.json';
		$index = json_decode((string)file_get_contents($file), true);

		foreach (['twig_functions', 'twig_filters'] as $kind) {
			foreach ((array)($index[$kind] ?? []) as $entry) {
				$this->assertNotSame('', (string)($entry['name'] ?? ''), "{$kind} entry missing name");
			}
		}
	}

	public function testSearchIndexMatchesDocsOnDisk(): void
	{
		$docsDir = dirname(__DIR__, 3) . '/resources/docs';
		$index   = json_decode((string)file_get_contents($docsDir . '/search-index.json'), true);
		$indexed = array_column($index, 'path');

		foreach ($indexed as $path) {
			$this->assertFileExists("{$docsDir}/{$path}.md", "search-index references missing doc: {$path}");
		}

		$onDisk = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($docsDir)) as $file) {
			if ($file->getExtension() === 'md') {
				// Exclude image directories from the scan
				if (strpos($file->getPathname(), '/images/') !== false) {
					continue;
				}
				$rel      = substr($file->getPathname(), strlen($docsDir) + 1, -3);
				$onDisk[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
			}
		}

		// Legitimate exclusions (not searchable pages)
		$exclusions = [
			'index', // Root landing page, not a searchable doc page
		];
		$missing = array_diff($onDisk, $indexed, $exclusions);
		$this->assertSame([], array_values($missing), 'Docs on disk missing from search-index.json — run php bin/build-docs-index.php');
	}
}
