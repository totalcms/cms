<?php

declare(strict_types=1);

use Tests\Performance\Bench\BenchCase;

function benchFixtureBase(): string
{
	$base = sys_get_temp_dir() . '/tcms-bench-fixtures-' . ($_SERVER['TEST_TOKEN'] ?? '0') . '-' . bin2hex(random_bytes(4));
	mkdir($base . '/100', 0755, true);
	file_put_contents($base . '/100/fixture.json', '{}');
	mkdir($base . '/1000', 0755, true); // a crashed build: no fixture.json

	return $base;
}

it('lists available sizes and a build command for each missing one', function (): void {
	$status = BenchCase::fixtureStatus(benchFixtureBase());

	expect($status['sizes']['available'])->toBe(['100']);
	expect($status['sizes']['missing'])->toBe([
		'php bin/perf-fixtures.php --size=1000',
		'php bin/perf-fixtures.php --size=10000',
		'php bin/perf-fixtures.php --size=50000',
	]);
});

it('reports router page-count fixtures separately', function (): void {
	$status = BenchCase::fixtureStatus(benchFixtureBase());

	expect($status['pages']['available'])->toBe([]);
	expect($status['pages']['missing'][0])->toBe('php bin/perf-fixtures.php --size=1000 --pages=10 --root=tmp/perf/1000-p10');
});
