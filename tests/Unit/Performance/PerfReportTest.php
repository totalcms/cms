<?php

declare(strict_types=1);

use Tests\Performance\PerfReport;

/**
 * @param array<string,array<string,array{0:float,1:int}>> $subjects subject => set => [mode µs, mem bytes]
 */
function perfReportXml(array $subjects): string
{
	$xml = '<?xml version="1.0"?><phpbench version="1.7.0"><suite tag="t"><benchmark class="\Tests\Performance\Bench\IndexBench">';
	foreach ($subjects as $subject => $sets) {
		$xml .= sprintf('<subject name="%s">', $subject);
		foreach ($sets as $set => [$mode, $mem]) {
			$xml .= sprintf(
				'<variant revs="1"><parameter-set name="%s"/><iteration time-net="%d" mem-peak="%d"/><iteration time-net="%d" mem-peak="%d"/><stats mode="%F" mean="%F"/></variant>',
				$set,
				(int)$mode,
				$mem - 10,
				(int)$mode,
				$mem,
				$mode,
				$mode,
			);
		}
		$xml .= '</subject>';
	}

	return $xml . '</benchmark></suite></phpbench>';
}

it('writes one CSV row per subject and size with an exact header', function (): void {
	$report = PerfReport::fromXml(perfReportXml(['benchLoad' => ['100' => [1000.0, 2 * 1048576]]]));

	expect(explode("\n", trim($report->toCsv())))->toBe([
		'bench,subject,size,time_ms,mem_peak_mb',
		'IndexBench,benchLoad,100,1.000,2.0',
	]);
});

it('does not flag a subject that grows linearly', function (): void {
	$report = PerfReport::fromXml(perfReportXml(['benchLinear' => [
		'100'  => [1000.0, 1048576],
		'1000' => [10000.0, 10 * 1048576],
	]]));

	expect($report->superlinear())->toBe([]);
});

it('flags a subject whose time grows faster than its size', function (): void {
	$report = PerfReport::fromXml(perfReportXml(['benchQuadratic' => [
		'100'  => [1000.0, 1048576],
		'1000' => [100000.0, 1048576],
	]]));

	expect($report->superlinear())->toBe([[
		'subject'   => 'IndexBench::benchQuadratic',
		'from'      => 100,
		'to'        => 1000,
		'sizeRatio' => 10.0,
		'timeRatio' => 100.0,
		'memRatio'  => 1.0,
	]]);
});

it('orders sizes numerically and reads page counts from router sets', function (): void {
	$report = PerfReport::fromXml(perfReportXml(['benchMatch' => [
		'1000-p500' => [500000.0, 1048576],
		'1000-p10'  => [1000.0, 1048576],
	]]));

	$flag = $report->superlinear()[0];
	expect($flag['from'])->toBe(10);
	expect($flag['to'])->toBe(500);
});

it('lists rows over the BSH memory limit', function (): void {
	$report = PerfReport::fromXml(perfReportXml(['benchBig' => [
		'10000' => [1000.0, 100 * 1048576],
		'50000' => [1000.0, 300 * 1048576],
	]]));

	$over = $report->overMemoryLimit(256);
	expect($over)->toHaveCount(1);
	expect($over[0]['size'])->toBe('50000');
});
