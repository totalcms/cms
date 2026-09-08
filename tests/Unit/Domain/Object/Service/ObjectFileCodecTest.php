<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Service\ObjectFileCodec;

describe('ObjectFileCodec', function (): void {
	$codec = new ObjectFileCodec();

	test('json is today\'s pretty JSON, byte for byte', function () use ($codec): void {
		$data = ['id' => 'a', 'title' => 'Hello / world', 'tags' => ['x', 'y'], 'draft' => false];
		expect($codec->encode($data, 'json', 'content'))
			->toBe((string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		expect($codec->decode($codec->encode($data, 'json', null), 'json', null))->toBe($data);
		expect($codec->extension('json'))->toBe('.json')->and($codec->extension('markdown'))->toBe('.md');
	});

	test('markdown puts every property in frontmatter and the body property after it', function () use ($codec): void {
		$data = ['id' => 'load-more', 'title' => 'Load More', 'related' => ['twig/render'], 'updated' => '2026-08-27T10:00:00+00:00', 'content' => "# Load More\n\nText."];
		$file = $codec->encode($data, 'markdown', 'content');

		expect($file)->toStartWith("---\nid: load-more\n")
			->toMatch("/title: ['\"]Load More['\"]/")
			->toContain("related:\n  - twig/render")
			->toContain("updated: '2026-08-27T10:00:00+00:00'")
			->toEndWith("---\n\n# Load More\n\nText.\n");
		expect($file)->not->toContain('content:');
		expect($codec->decode($file, 'markdown', 'content'))->toBe($data);
	});

	test('round-trips every value shape and keeps timestamps as strings', function () use ($codec): void {
		$data = [
			'id'      => 'x', 'n' => 3, 'f' => 1.5, 'b' => true, 'nul' => null, 'empty' => [], 'title' => 'No: really',
			'image'   => ['name' => 'a.jpg', 'size' => 10, 'exif' => ['nodata' => '']],
			'deck'    => [['id' => 'i1', 'label' => 'yes'], ['id' => 'i2', 'label' => 'null']],
			'when'    => '2026-01-01', 'multi' => "line one\nline two\n",
			'content' => '',
		];
		expect($codec->decode($codec->encode($data, 'markdown', 'content'), 'markdown', 'content'))->toBe($data);
	});

	test('a multi-line string as the last frontmatter property keeps its trailing newline through a literal block', function () use ($codec): void {
		$data = ['id' => 'x', 'multi' => "line one\nline two\n"];
		$file = $codec->encode($data, 'markdown', null);
		expect($file)->toContain('multi: |');
		expect($codec->decode($file, 'markdown', null))->toBe($data);
	});

	test('a --- line embedded in a non-body multi-line property does not fool the closing fence', function () use ($codec): void {
		$data = ['id' => 'x', 'notes' => "para one\n---\npara two\n", 'content' => 'BODYTEXT'];
		$file = $codec->encode($data, 'markdown', 'content');
		expect($file)->toContain('notes: |');
		expect($codec->decode($file, 'markdown', 'content'))->toBe($data);
	});

	test('a --- line inside the body still works', function () use ($codec): void {
		$data = ['id' => 'x', 'content' => "before\n---\nafter"];
		expect($codec->decode($codec->encode($data, 'markdown', 'content'), 'markdown', 'content'))->toBe($data);
	});

	test('CRLF file keeps interior line endings in the body verbatim', function () use ($codec): void {
		$file = "---\r\nid: x\r\n---\r\n\r\nHello\r\nWorld\r\n";
		$data = $codec->decode($file, 'markdown', 'content');
		expect($data['id'])->toBe('x');
		expect($data['content'])->toBe("Hello\r\nWorld");
	});

	test('no body property means frontmatter only, and content stays in frontmatter', function () use ($codec): void {
		$data = ['id' => 'x', 'content' => ['not' => 'a string']];
		$file = $codec->encode($data, 'markdown', null);
		expect($file)->toMatch("/content:\\n  not: ['\"]a string['\"]/");
		expect($codec->decode($file, 'markdown', null))->toBe($data);
	});

	test('a non-string content is not a body even when content IS the configured body property', function () use ($codec): void {
		// encode() leaves a non-string `content` in the frontmatter (it can't
		// become a body). decode() must not then clobber that frontmatter
		// value with the empty body between the fences — it has to notice
		// the property is already present and leave it alone.
		$data = ['id' => 'x', 'content' => ['not', 'a', 'string']];
		$file = $codec->encode($data, 'markdown', 'content');
		expect($codec->decode($file, 'markdown', 'content'))->toBe($data);
	});

	test('a body that starts with --- survives', function () use ($codec): void {
		$data = ['id' => 'x', 'content' => "---\nnot frontmatter\n---"];
		expect($codec->decode($codec->encode($data, 'markdown', 'content'), 'markdown', 'content'))->toBe($data);
	});

	test('a file with no frontmatter is body only', function () use ($codec): void {
		expect($codec->decode("just text\n", 'markdown', 'content'))->toBe(['content' => 'just text']);
	});

	test('unparseable input throws', function () use ($codec): void {
		expect(fn () => $codec->decode("---\nid: [\n---\n", 'markdown', 'content'))->toThrow(UnexpectedValueException::class);
		expect(fn () => $codec->decode('{not json', 'json', null))->toThrow(UnexpectedValueException::class);
	});

	test('malformed UTF-8 throws on encode rather than truncating the file to an empty string', function () use ($codec): void {
		// Plain json_encode() returns '' (not false, not an error) on
		// malformed UTF-8. A caller that writes that return value straight to
		// disk truncates the object file to zero bytes and loses it. The
		// codec must throw instead so the save aborts before anything is
		// written.
		expect(fn () => $codec->encode(['id' => 'x', 'title' => "\xB1\x31"], 'json', null))
			->toThrow(UnexpectedValueException::class);
	});

	test('bodyProperty is content only when string-typed', function () use ($codec): void {
		expect($codec->bodyProperty(['content' => ['type' => 'string', 'field' => 'styledtext']]))->toBe('content');
		expect($codec->bodyProperty(['content' => ['field' => 'markdown']]))->toBe('content');
		expect($codec->bodyProperty(['content' => ['type' => 'array', 'field' => 'deck']]))->toBeNull();
		expect($codec->bodyProperty(['body' => ['type' => 'string']]))->toBeNull();
	});
});
