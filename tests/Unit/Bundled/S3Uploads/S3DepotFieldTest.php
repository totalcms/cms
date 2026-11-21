<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Field\S3DepotField;
use TotalCMS\Bundled\S3Uploads\Field\S3FileField;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\TotalForm;

require_once __DIR__ . '/helpers.php';

describe('S3DepotField', function (): void {
	$item = fn (string $id, string $name): array => [
		'id' => $id, 'key' => "site/docs/d1/files/{$id}-x.pdf", 'name' => $name, 'size' => 1536, 'mime' => 'application/pdf',
		'etag' => '', 'uploadedAt' => '2026-09-05T14:02:11+00:00', 'uploadedBy' => 'joe',
	];

	/** @param array<string,mixed> $args */
	$build = function (array $args = []): string {
		$form = new TotalForm(formServices(), new FormOptions(api: '/api', collection: 'docs'));

		return (new S3DepotField($form, 'files', ...($args + ['field' => 's3depot', 'label' => 'Files'])))->build();
	};

	afterEach(fn () => S3FileField::configure(null));

	test('an empty depot shows a drop zone that takes several files', function () use ($build): void {
		$html = $build();

		expect($html)->toContain('data-type="s3depot"')
			->and($html)->toContain('class="s3-field s3-depot" data-state="empty"')
			->and($html)->toMatch('/<input[^>]*type="file"[^>]*multiple/')
			->and($html)->toContain('<ul class="s3-list"></ul>')
			->and($html)->toContain('<template class="s3-item-template">')
			->and(strpos($html, 'name="files"'))->toBeLessThan(strpos($html, 'type="file"'));
	});

	test('each stored file gets a card, in stored order, named by its id', function () use ($build, $item): void {
		$value = [$item('1a1b2c3d', 'Second <b>.pdf'), 'junk', $item('0a1b2c3d', 'First.pdf')];
		$html  = $build(['value' => $value]);
		$list  = substr($html, (int)strpos($html, '<ul class="s3-list">'), (int)strpos($html, '</ul>') - (int)strpos($html, '<ul class="s3-list">'));

		expect(substr_count($list, '<li class="s3-card"'))->toBe(2)
			->and(strpos($list, 'data-file-id="1a1b2c3d"'))->toBeLessThan(strpos($list, 'data-file-id="0a1b2c3d"'))
			->and($list)->toContain('Second &lt;b&gt;.pdf')
			->and($list)->toContain('1.5 KB')
			->and($html)->toContain('data-state="filled"')
			// The stored value is handed back whole: the field does not drop what it cannot show.
			->and($html)->toContain('&quot;junk&quot;');
	});

	test('uploads off shows the notice shared with the single-file field', function () use ($build, $item): void {
		S3FileField::configure('Uploads are off: S3 Uploads is not configured. A super admin can set it up in Extensions → S3 Uploads.');
		$html = $build(['value' => [$item('0a1b2c3d', 'First.pdf')]]);

		expect($html)->toContain('class="s3-notice"')->and($html)->toContain('data-uploads="off"')->and($html)->toContain('First.pdf');
	});

	test('inside a card or deck the field says it is not supported there', function () use ($build): void {
		expect($build(['nestedPath' => 'mycard']))->toContain('S3 fields work only as top-level properties.');
	});
});
