<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Field\S3FileField;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\TotalForm;

require_once __DIR__ . '/helpers.php';

describe('S3FileField', function (): void {
	$pointer = [
		'id' => '0a1b2c3d', 'key' => 'site/blog/post-1/brochure/0a1b2c3d-brochure.pdf', 'name' => 'Brochure <2026>.pdf',
		'size' => 48211334, 'mime' => 'application/pdf', 'etag' => '"e"', 'uploadedAt' => '2026-09-05T14:02:11+00:00', 'uploadedBy' => 'joe',
	];

	/** @param array<string,mixed> $args */
	$build = function (array $args = []): string {
		$form = new TotalForm(formServices(), new FormOptions(api: '/api', collection: 'blog'));

		return (new S3FileField($form, 'brochure', ...($args + ['field' => 's3file', 'label' => 'Brochure'])))->build();
	};

	afterEach(fn () => S3FileField::configure(null));

	test('the stylesheet hides the input that carries the value, for both field types', function () use ($build): void {
		// Core hides each upload field's value input by the field's own class;
		// nothing hides one generically. Without this rule the editor sees a
		// text box holding raw JSON above the drop zone.
		$css = (string)file_get_contents(dirname(__DIR__, 4) . '/resources/extensions/totalcms/s3-uploads/assets/s3-field.css');

		expect($build())->toMatch('/class="form-field s3file-field[^"]*"/')
			->and($css)->toMatch('/\.form-field\.s3file-field > \.form-group > input\[data-proxy\],\s*\.form-field\.s3depot-field > \.form-group > input\[data-proxy\] \{[^}]*opacity: 0;/');
	});

	test('the stylesheet styles only the field on an object form, not the schema editor', function (): void {
		// The schema editor puts the same `s3file-field` class on a property's
		// row and dialog, with every option of the property inside it. A rule
		// that reaches all `.form-group`s under that class restyles those too:
		// their icons dropped below their inputs.
		$css = (string)file_get_contents(dirname(__DIR__, 4) . '/resources/extensions/totalcms/s3-uploads/assets/s3-field.css');
		preg_match_all('/^[^{}\/*\n][^{}]*\{/m', $css, $rules);

		$loose = array_filter($rules[0], fn (string $selector): bool => preg_match('/(?<!\.form-field)\.s3(file|depot)-field\b/', $selector) === 1);

		expect(array_values($loose))->toBe([]);
	});

	test('a stored file is one row: its kind, an icon, and icon buttons that still say what they do', function () use ($build, $pointer): void {
		$html = $build(['value' => $pointer]);

		expect($html)->toContain('data-kind="doc"')
			->and($html)->toContain('<span class="s3-icon" aria-hidden="true"></span>')
			->and($html)->toMatch('#<a[^>]*class="s3-action s3-open"[^>]*title="Open"[^>]*><span class="s3-label">Open</span></a>#')
			->and($html)->toMatch('#<button[^>]*class="s3-action s3-replace"[^>]*title="Replace"[^>]*><span class="s3-label">Replace</span></button>#')
			->and($html)->toMatch('#<button[^>]*class="s3-action s3-remove"[^>]*title="Remove"#')
			// One file has nothing to be reordered among.
			->and($html)->not->toContain('s3-handle');
	});

	test('a file\'s kind comes from its type, and from its name when the type says nothing', function (string $name, string $mime, string $kind): void {
		expect(S3FileField::kind($name, $mime))->toBe($kind);
	})->with([
		['photo.JPG', 'image/jpeg', 'image'],
		['episode.mp3', 'audio/mpeg', 'audio'],
		['clip.mov', 'video/quicktime', 'video'],
		['brochure.pdf', 'application/pdf', 'doc'],
		['notes.txt', 'text/plain', 'text'],
		['prices.csv', 'text/csv', 'sheet'],
		['deck.pptx', 'application/octet-stream', 'slides'],
		['site.zip', 'application/zip', 'archive'],
		['data.json', 'application/json', 'code'],
		['photo.heic', 'application/octet-stream', 'image'],
		['mystery', 'application/octet-stream', 'file'],
		['', '', 'file'],
	]);

	test('an empty field shows the drop zone and an empty value', function () use ($build): void {
		$html = $build(['settings' => ['rules' => ['size' => ['max' => 5000]]]]);

		expect($html)->toContain('data-type="s3file"')
			->and($html)->toContain('data-settings="{&quot;rules&quot;:{&quot;size&quot;:{&quot;max&quot;:5000}}}"')
			->and($html)->toMatch('/<input[^>]*data-proxy[^>]*>/')
			->and($html)->toMatch('/<input[^>]*name="brochure"[^>]*>/')
			->and($html)->not->toMatch('/<input[^>]*name="brochure"[^>]*value=/')
			->and($html)->toContain('class="s3-field" data-state="empty"')
			->and($html)->toContain('class="s3-drop"')
			->and($html)->toContain('type="file"')
			->and($html)->not->toContain('s3-notice');
	});

	test('the proxy input comes first, so it is the field\'s input', function () use ($build): void {
		$html = $build();

		expect(strpos($html, 'name="brochure"'))->toBeLessThan(strpos($html, 'type="file"'));
	});

	test('a stored file shows its card, with the value as JSON on the proxy input', function () use ($build, $pointer): void {
		$html = $build(['value' => $pointer]);

		expect($html)->toContain('value="' . htmlspecialchars((string)json_encode($pointer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"')
			->and($html)->toContain('data-state="filled"')
			->and($html)->toContain('Brochure &lt;2026&gt;.pdf')
			->and($html)->not->toContain('Brochure <2026>.pdf')
			->and($html)->toContain('46 MB')
			->and($html)->toContain('s3-open')
			->and($html)->toContain('s3-replace')
			->and($html)->toContain('s3-remove');
	});

	test('a value that is not a pointer renders as empty and is kept as it is', function () use ($build): void {
		$html = $build(['value' => ['oops' => true]]);

		expect($html)->toContain('data-state="empty"')
			->and($html)->toContain('value="{&quot;oops&quot;:true}"');
	});

	test('uploads off shows the notice and marks the field read-only', function () use ($build, $pointer): void {
		S3FileField::configure('Uploads are off: S3 Uploads is not configured. A super admin can set it up in Extensions → S3 Uploads.');
		$html = $build(['value' => $pointer]);

		expect($html)->toContain('class="s3-notice"')
			->and($html)->toContain('Uploads are off: S3 Uploads is not configured.')
			->and($html)->toContain('data-uploads="off"')
			->and($html)->toContain('Brochure &lt;2026&gt;.pdf');
	});

	test('inside a card or deck the field says it is not supported there', function () use ($build): void {
		$html = $build(['nestedPath' => 'mycard']);

		expect($html)->toContain('S3 fields work only as top-level properties.')
			->and($html)->toContain('data-uploads="off"');
	});
});
