<?php

declare(strict_types=1);

use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;

require_once __DIR__ . '/helpers.php';

describe('KeyBuilder', function (): void {
	test('a file name becomes an ASCII slug that keeps a short extension', function (string $given, string $expected): void {
		expect(KeyBuilder::slug($given))->toBe($expected);
	})->with([
		'spaces and brackets'   => ['Brochure 2026 (final).PDF', 'brochure-2026-final.pdf'],
		'accents'               => ['résumé.pdf', 'r-sum.pdf'],
		'dot file'              => ['.htaccess', 'htaccess'],
		'no extension'          => ['noext', 'noext'],
		'empty'                 => ['', 'file'],
		'only symbols'          => ['???.pdf', 'file.pdf'],
		'extension too long'    => ['notes.toolongextension', 'notes'],
		'extension not alnum'   => ['notes.p-f', 'notes'],
		'two dots'              => ['archive.tar.gz', 'archive-tar.gz'],
		'quotes and unicode'    => ['"日本語" file.docx', 'file.docx'],
		'path separators'       => ['../../etc/passwd', 'passwd'],
		'windows path'          => ['C:\\docs\\a b.pdf', 'a-b.pdf'],
	]);

	test('a long base name is cut to 80 characters', function (): void {
		$slug = KeyBuilder::slug(str_repeat('a', 200) . '.pdf');

		expect($slug)->toBe(str_repeat('a', 80) . '.pdf');
	});

	test('a key sits under the prefix, the collection, the object and the property', function (): void {
		$keys = new KeyBuilder('site/');

		expect($keys->build('blog', 'post 1', 'audio', 'a b.mp3', null, '0a1b2c3d'))->toBe('site/blog/post-1/audio/0a1b2c3d-a-b.mp3')
			->and((new KeyBuilder(''))->build('blog', 'post-1', 'audio', 'a.mp3', null, '0a1b2c3d'))->toBe('blog/post-1/audio/0a1b2c3d-a.mp3');
	});

	test('a new key gets a fresh 8-hex id', function (): void {
		$keys = new KeyBuilder('site/');
		$a    = $keys->build('blog', 'p', 'audio', 'a.mp3', null);
		$b    = $keys->build('blog', 'p', 'audio', 'a.mp3', null);

		expect($a)->toMatch('#^site/blog/p/audio/[0-9a-f]{8}-a\.mp3$#')->and($a)->not->toBe($b);
	});

	test('a folder replaces the per-object path, cleaned segment by segment', function (): void {
		$keys = new KeyBuilder('site/');

		expect($keys->build('blog', 'post-1', 'audio', 'a.mp3', 'media//x/../y z', '0a1b2c3d'))->toBe('site/media/x/y-z/0a1b2c3d-a.mp3');
	});

	test('a segment that is only dots is refused', function (): void {
		expect(KeyBuilder::segment('..'))->toBe('')
			->and(KeyBuilder::segment('.'))->toBe('')
			->and(KeyBuilder::segment('a/b'))->toBe('a-b')
			->and(KeyBuilder::segment('v1.2_final-x'))->toBe('v1.2_final-x')
			->and(fn () => (new KeyBuilder(''))->build('blog', '..', 'audio', 'a.mp3', null))->toThrow(InvalidArgumentException::class);
	});

	test('only well-formed keys under the prefix are inside it', function (string $key, bool $inside): void {
		expect((new KeyBuilder('site/'))->isInsidePrefix($key))->toBe($inside);
	})->with([
		['site/blog/p/audio/0a1b2c3d-a.mp3', true],
		['other/blog/p/audio/0a1b2c3d-a.mp3', false],
		['/site/blog/a.mp3', false],
		['site/../x', false],
		['site/./x', false],
		['site', false],
		['sitemap/a.mp3', false],
		['', false],
	]);

	test('with an empty prefix every well-formed key is inside', function (): void {
		$keys = new KeyBuilder('');

		expect($keys->isInsidePrefix('anything/a.mp3'))->toBeTrue()
			->and($keys->isInsidePrefix('/anything/a.mp3'))->toBeFalse()
			->and($keys->isInsidePrefix('a/../b'))->toBeFalse()
			->and($keys->isInsidePrefix(''))->toBeFalse();
	});

	test('a key is owned only by the object and property whose path it is under', function (): void {
		$keys = new KeyBuilder('site/');
		$key  = 'site/blog/post-1/audio/0a1b2c3d-a.mp3';

		expect($keys->ownedPrefix('blog', 'post-1', 'audio', null))->toBe('site/blog/post-1/audio/')
			->and($keys->isOwnedBy($key, 'blog', 'post-1', 'audio', null))->toBeTrue()
			->and($keys->isOwnedBy($key, 'blog', 'post-10', 'audio', null))->toBeFalse()
			->and($keys->isOwnedBy($key, 'blog', 'post-1', 'video', null))->toBeFalse()
			->and($keys->isOwnedBy('site/blog/post-1/audio/../../x.mp3', 'blog', 'post-1', 'audio', null))->toBeFalse();
	});

	test('nothing is owned when the property uses a folder', function (): void {
		$keys = new KeyBuilder('site/');

		expect($keys->ownedPrefix('blog', 'post-1', 'audio', 'media'))->toBeNull()
			->and($keys->isOwnedBy('site/media/0a1b2c3d-a.mp3', 'blog', 'post-1', 'audio', 'media'))->toBeFalse()
			->and($keys->isOwnedBy('site/blog/post-1/audio/0a1b2c3d-a.mp3', 'blog', 'post-1', 'audio', 'media'))->toBeFalse();
	});

	test('the id is read back from a key', function (): void {
		expect(KeyBuilder::idFromKey('site/a/b/c/0a1b2c3d-x.pdf'))->toBe('0a1b2c3d')
			->and(KeyBuilder::idFromKey('site/a/b/c/x.pdf'))->toBeNull()
			->and(KeyBuilder::idFromKey('0A1B2C3D-x.pdf'))->toBeNull();
	});
});
