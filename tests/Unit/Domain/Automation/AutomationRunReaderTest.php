<?php

declare(strict_types=1);

use Tests\Fakes\InMemoryFilesystem;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;

/** @param array<string,mixed> $record */
function writeRun(InMemoryFilesystem $fs, string $id, string $runId, array $record): void
{
	$fs->write(".system/automations/{$id}/runs/{$runId}.json", (string)json_encode($record));
}

it('returns run history newest-first', function (): void {
	$fs = new InMemoryFilesystem();
	writeRun($fs, 'daily', '20260101T010000-a', ['runId' => 'r1', 'status' => 'success']);
	writeRun($fs, 'daily', '20260102T010000-b', ['runId' => 'r2', 'status' => 'failed']);
	writeRun($fs, 'daily', '20260103T010000-c', ['runId' => 'r3', 'status' => 'success']);

	$history = (new AutomationRunReader($fs))->history('daily');

	expect($history)->toHaveCount(3);
	expect(array_column($history, 'runId'))->toBe(['r3', 'r2', 'r1']); // time-prefixed ids, newest first
});

it('caps history at 50 records', function (): void {
	$fs = new InMemoryFilesystem();
	for ($i = 1; $i <= 60; $i++) {
		writeRun($fs, 'daily', sprintf('202601%02dT010000-x', $i), ['runId' => "r{$i}"]);
	}

	expect((new AutomationRunReader($fs))->history('daily'))->toHaveCount(50);
});

it('newest returns the most recent run, or null when none', function (): void {
	$fs = new InMemoryFilesystem();
	writeRun($fs, 'daily', '20260101T010000-a', ['runId' => 'old', 'status' => 'failed']);
	writeRun($fs, 'daily', '20260105T010000-b', ['runId' => 'new', 'status' => 'success']);

	$reader = new AutomationRunReader($fs);
	expect($reader->newest('daily')['runId'])->toBe('new');
	expect($reader->newest('never-run'))->toBeNull();
});

it('latestPerAutomation returns the newest run for each automation that has any', function (): void {
	$fs = new InMemoryFilesystem();
	writeRun($fs, 'daily', '20260101T010000-a', ['runId' => 'd1', 'status' => 'success']);
	writeRun($fs, 'daily', '20260103T010000-b', ['runId' => 'd2', 'status' => 'failed']);
	writeRun($fs, 'weekly', '20260102T010000-c', ['runId' => 'w1', 'status' => 'success']);

	$latest = (new AutomationRunReader($fs))->latestPerAutomation();

	expect($latest)->toHaveKeys(['daily', 'weekly']);
	expect($latest['daily']['runId'])->toBe('d2');   // newest of daily's two runs
	expect($latest['weekly']['runId'])->toBe('w1');
});

it('skips malformed run files instead of throwing', function (): void {
	$fs = new InMemoryFilesystem();
	writeRun($fs, 'daily', '20260101T010000-a', ['runId' => 'good']);
	$fs->write('.system/automations/daily/runs/20260102T010000-b.json', '{ not json');

	$history = (new AutomationRunReader($fs))->history('daily');

	expect($history)->toHaveCount(1);
	expect($history[0]['runId'])->toBe('good');
});

it('returns empty results when nothing has run', function (): void {
	$reader = new AutomationRunReader(new InMemoryFilesystem());

	expect($reader->latestPerAutomation())->toBe([]);
	expect($reader->history('whatever'))->toBe([]);
	expect($reader->newest('whatever'))->toBeNull();
});
