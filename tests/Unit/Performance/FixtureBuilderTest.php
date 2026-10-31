<?php

declare(strict_types=1);

// The generator runs as its own process: it must boot Total CMS in production
// mode against the fixture's tcms.php, never the test sandbox this process
// already booted.

function perfFixtureRoot(string $name): string
{
	$base = dirname(__DIR__, 3) . '/tmp/perf-test/' . ($_SERVER['TEST_TOKEN'] ?? '0');

	return $base . '/' . $name;
}

/** @return array{0:int,1:string} exit code and combined output */
function runPerfFixtures(string ...$args): array
{
	$script = dirname(__DIR__, 3) . '/bin/perf-fixtures.php';
	$env    = getenv();
	unset($env['APP_ENV'], $env['TCMS_TEST_DATADIR'], $env['TCMS_TEST_CACHEDIR'], $env['TCMS_TEST_TMPDIR']);

	$proc = proc_open(
		array_merge([PHP_BINARY, $script], $args),
		[1 => ['pipe', 'w'], 2 => ['redirect', 1]],
		$pipes,
		null,
		$env,
	);
	$output = (string)stream_get_contents($pipes[1]);
	fclose($pipes[1]);

	return [proc_close($proc), $output];
}

/** @return list<string> */
function perfObjectIds(string $root, string $collection): array
{
	$files = glob($root . '/tcms-data/' . $collection . '/*.json') ?: [];
	$ids   = array_map(fn (string $f): string => basename($f, '.json'), $files);
	sort($ids);

	return $ids;
}

function removePerfRoot(string $root): void
{
	if (is_dir($root)) {
		exec('/bin/rm -rf ' . escapeshellarg($root));
	}
}

afterEach(function (): void {
	removePerfRoot(dirname(perfFixtureRoot('x')));
});

it('builds a fixture with exactly N posts and a matching index', function (): void {
	$root = perfFixtureRoot('a');
	[$code, $out] = runPerfFixtures('--size=25', '--pages=10', '--root=' . $root);

	expect($code)->toBe(0, $out);
	expect(perfObjectIds($root, 'posts'))->toHaveCount(25);

	$index = json_decode((string)file_get_contents($root . '/tcms-data/posts/.index.json'), true);
	expect($index['objects'])->toHaveCount(25);
	expect(perfObjectIds($root, 'builder-pages'))->toHaveCount(10);

	$manifest = json_decode((string)file_get_contents($root . '/fixture.json'), true);
	expect($manifest['objects'])->toBe(25);
	expect($manifest['pages'])->toBe(10);
	expect($manifest['seed'])->toBe(42);
});

it('is deterministic for a seed', function (): void {
	$a = perfFixtureRoot('a');
	$b = perfFixtureRoot('b');
	runPerfFixtures('--size=15', '--pages=5', '--root=' . $a);
	runPerfFixtures('--size=15', '--pages=5', '--root=' . $b);

	expect(perfObjectIds($a, 'posts'))->toHaveCount(15);
	expect(perfObjectIds($a, 'posts'))->toBe(perfObjectIds($b, 'posts'));
});

it('refuses a non-empty root without force', function (): void {
	$root = perfFixtureRoot('a');
	runPerfFixtures('--size=5', '--pages=2', '--root=' . $root);

	[$code, $out] = runPerfFixtures('--size=10', '--pages=2', '--root=' . $root);
	expect($code)->not->toBe(0);
	expect($out)->toContain('--force');
	expect(perfObjectIds($root, 'posts'))->toHaveCount(5);

	[$code] = runPerfFixtures('--size=10', '--pages=2', '--root=' . $root, '--force');
	expect($code)->toBe(0);
	expect(perfObjectIds($root, 'posts'))->toHaveCount(10);
});

it('the fixture schema has no media fields', function (): void {
	$schema = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/tests/Performance/Fixtures/perf-post.json'), true);

	foreach ($schema['properties'] as $name => $property) {
		expect($property['field'] ?? '')->not->toBeIn(['image', 'gallery', 'file', 'depot', 'video'], $name);
	}
});

it('will not wipe a folder that is not a fixture, even with force', function (): void {
	$root = perfFixtureRoot('not-a-fixture');
	mkdir($root, 0755, true);
	file_put_contents($root . '/keep.txt', 'mine');

	[$code, $out] = runPerfFixtures('--size=5', '--pages=2', '--root=' . $root, '--force');

	expect($code)->not->toBe(0);
	expect($out)->toContain('fixture.json');
	expect($root . '/keep.txt')->toBeFile();
});

it('writes absolute data paths for a relative root', function (): void {
	$relative = 'tmp/perf-test/' . ($_SERVER['TEST_TOKEN'] ?? '0') . '/rel';
	[$code, $out] = runPerfFixtures('--size=3', '--pages=1', '--root=' . $relative);

	expect($code)->toBe(0, $out);
	$settings = require perfFixtureRoot('rel') . '/public/tcms.php';
	expect($settings['datadir'])->toStartWith('/');
	expect($settings['cachedir'])->toStartWith('/');
});

it('will not replace a site collection it did not create', function (): void {
	$site = perfFixtureRoot('site');
	mkdir($site . '/public', 0755, true);
	mkdir($site . '/tcms-data/posts', 0755, true);
	file_put_contents($site . '/tcms-data/posts/real-post.json', '{"id":"real-post"}');

	[$code, $out] = runPerfFixtures('--size=5', '--pages=2', '--site=' . $site, '--force');

	expect($code)->not->toBe(0);
	expect($out)->toContain('fixture.json');
	expect($site . '/tcms-data/posts/real-post.json')->toBeFile();
});
