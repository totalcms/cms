<?php

use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;

describe('DepotBrowserRenderer', function (): void {
	test('DepotBrowserRenderer → render returns empty for empty files', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			['files' => []],
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toBe('');
	});

	test('DepotBrowserRenderer → render returns empty for null files', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			['files' => null],
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toBe('');
	});

	test('DepotBrowserRenderer → render creates container div', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<div class="cms-depot-browser"');
		expect($result)->toContain('</div>');
	});

	test('DepotBrowserRenderer → render includes data-settings attribute', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['preview' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('data-settings=');
		// The JSON is HTML-escaped, so check for the escaped version
		expect($result)->toContain('&quot;preview&quot;:true');
	});

	test('DepotBrowserRenderer → render creates file list', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<ul class="depot-browser-tree">');
		expect($result)->toContain('</ul>');
	});

	test('DepotBrowserRenderer → render includes file items', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'document.pdf', 'mime' => 'application/pdf', 'size' => 1024],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('depot-browser-item');
		expect($result)->toContain('document.pdf');
		expect($result)->toContain('data-ext="pdf"');
	});

	test('DepotBrowserRenderer → render includes download links when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['download' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<a href=');
		expect($result)->toContain('/download/obj-1/file.txt');
		expect($result)->toContain('download="file.txt"');
	});

	test('DepotBrowserRenderer → render shows span instead of link when download disabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['download' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<span class="file');
		expect($result)->not->toContain('<a href=');
	});

	test('DepotBrowserRenderer → render includes filter when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['filter' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('depot-browser-filter');
		expect($result)->toContain('type="search"');
		expect($result)->toContain('Filter files');
	});

	test('DepotBrowserRenderer → render excludes filter when disabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['filter' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->not->toContain('depot-browser-filter');
	});

	test('DepotBrowserRenderer → render includes preview dialog when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['preview' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<dialog');
		expect($result)->toContain('depot-browser-preview');
		expect($result)->toContain('preview-content');
		expect($result)->toContain('action-preview');
	});

	test('DepotBrowserRenderer → render excludes preview elements when disabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['preview' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->not->toContain('depot-browser-preview');
		expect($result)->not->toContain('action-preview');
	});

	test('DepotBrowserRenderer → render shows file size', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'large.zip', 'mime' => 'application/zip', 'size' => 1048576],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('file-size');
		expect($result)->toContain('1 MB');
	});

	test('DepotBrowserRenderer → render formats various file sizes correctly', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'tiny.txt', 'mime' => 'text/plain', 'size' => 500],
				['name' => 'small.txt', 'mime' => 'text/plain', 'size' => 1024],
				['name' => 'medium.zip', 'mime' => 'application/zip', 'size' => 1048576],
				['name' => 'large.iso', 'mime' => 'application/octet-stream', 'size' => 1073741824],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('500 B');
		expect($result)->toContain('1 KB');
		expect($result)->toContain('1 MB');
		expect($result)->toContain('1 GB');
	});

	test('DepotBrowserRenderer → render builds folder structure', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				[
					'name'  => 'documents',
					'mime'  => 'folder',
					'files' => [
						['name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 2048],
					],
				],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['folders' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('<details>');
		expect($result)->toContain('<summary');
		expect($result)->toContain('documents');
		expect($result)->toContain('report.pdf');
	});

	test('DepotBrowserRenderer → render flattens files when folders disabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'root.txt', 'mime' => 'text/plain', 'size' => 100],
				[
					'name'  => 'folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'nested.txt', 'mime' => 'text/plain', 'size' => 200],
					],
				],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['folders' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		// Should show flattened path
		expect($result)->toContain('folder/nested.txt');
		// Should not have folder structure
		expect($result)->not->toContain('<details>');
		expect($result)->not->toContain('<summary');
	});

	test('DepotBrowserRenderer → render humanizes filenames when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'my-document_file.test.pdf', 'mime' => 'application/pdf', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['humanize' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('My Document File Test');
	});

	test('DepotBrowserRenderer → render shows original filename when humanize disabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'my-document_file.pdf', 'mime' => 'application/pdf', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['humanize' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('my-document_file.pdf');
	});

	test('DepotBrowserRenderer → render includes comments when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100, 'comments' => 'Important notes here'],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['comments' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('file-comments');
		expect($result)->toContain('Important notes here');
	});

	test('DepotBrowserRenderer → render excludes empty comments', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100, 'comments' => '   '],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['comments' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->not->toContain('file-comments');
	});

	test('DepotBrowserRenderer → render includes tags when enabled', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 100, 'tags' => ['important', 'draft']],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['tags' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('file-tags');
		expect($result)->toContain('important');
		expect($result)->toContain('draft');
	});

	test('DepotBrowserRenderer → render escapes HTML in filenames', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => '<script>alert("xss")</script>.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->not->toContain('<script>alert');
		expect($result)->toContain('&lt;script&gt;');
	});

	test('DepotBrowserRenderer → render sorts folders before files', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'zebra.txt', 'mime' => 'text/plain', 'size' => 100],
				['name' => 'archive', 'mime' => 'folder', 'files' => []],
				['name' => 'alpha.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['folders' => true]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		// Folder should appear before files
		$folderPos = strpos($result, 'archive');
		$zebraPos  = strpos($result, 'zebra.txt');
		$alphaPos  = strpos($result, 'alpha.txt');

		expect($folderPos)->toBeLessThan($zebraPos);
		expect($folderPos)->toBeLessThan($alphaPos);
	});

	test('DepotBrowserRenderer → render includes stream URL in data attribute', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'video.mp4', 'mime' => 'video/mp4', 'size' => 1000000],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('data-stream-url="/stream/obj-1/video.mp4"');
	});

	test('DepotBrowserRenderer → render handles files with zero size', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => 'empty.txt', 'mime' => 'text/plain', 'size' => 0],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		// Should not show file-size span for zero size
		expect($result)->not->toContain('0 B');
	});

	test('DepotBrowserRenderer → render includes custom class', function (): void {
		$renderer = new DepotBrowserRenderer();

		$result = $renderer->render(
			'obj-1',
			sampleDepot(),
			array_merge(defaultOptions(), ['class' => 'custom-class']),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('cms-depot-browser custom-class');
	});

	test('DepotBrowserRenderer → render skips files without name', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				['name' => '', 'mime' => 'text/plain', 'size' => 100],
				['name' => 'valid.txt', 'mime' => 'text/plain', 'size' => 100],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			defaultOptions(),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('valid.txt');
		// Should only have one file item
		expect(substr_count($result, 'depot-browser-item'))->toBe(1);
	});

	test('DepotBrowserRenderer → render handles nested folder paths in flat mode', function (): void {
		$renderer = new DepotBrowserRenderer();

		$depot = [
			'files' => [
				[
					'name'  => 'level1',
					'mime'  => 'folder',
					'files' => [
						[
							'name'  => 'level2',
							'mime'  => 'folder',
							'files' => [
								['name' => 'deep.txt', 'mime' => 'text/plain', 'size' => 100],
							],
						],
					],
				],
			],
		];

		$result = $renderer->render(
			'obj-1',
			$depot,
			array_merge(defaultOptions(), ['folders' => false]),
			fn ($id, $name, $opts): string => "/download/$id/$name",
			fn ($id, $name, $opts): string => "/stream/$id/$name",
		);

		expect($result)->toContain('level1/level2/deep.txt');
	});
});

/**
 * Default options for rendering.
 *
 * @return array<string,mixed>
 */
function defaultOptions(): array
{
	return [
		'filter'   => false,
		'preview'  => false,
		'download' => true,
		'folders'  => true,
		'humanize' => false,
		'comments' => false,
		'tags'     => false,
		'class'    => '',
	];
}

/**
 * Sample depot data for testing.
 *
 * @return array<string,mixed>
 */
function sampleDepot(): array
{
	return [
		'files' => [
			['name' => 'test.txt', 'mime' => 'text/plain', 'size' => 100],
		],
	];
}
