<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\PullCommand;
use TotalCMS\TotalCMS;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/helpers.php';

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);

	$this->tmpDir = sys_get_temp_dir() . '/tcms-pull-test-' . uniqid();
	mkdir($this->tmpDir . '/.system', 0755, true);
	file_put_contents($this->tmpDir . '/.system/settings.json', (string) json_encode([
		'sync' => ['url' => 'https://production.example.com', 'key' => 'test-key'],
	]));

	$this->totalcms->config = createTestConfig(['datadir' => $this->tmpDir]);
});

afterEach(function (): void {
	@unlink($this->tmpDir . '/.system/settings.json');
	@rmdir($this->tmpDir . '/.system');
	@rmdir($this->tmpDir);
});

it('errors when sync not configured', function (): void {
	file_put_contents($this->tmpDir . '/.system/settings.json', '{}');

	$app     = new Application();
	$command = new PullCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
});

it('errors when sync URL is empty', function (): void {
	file_put_contents($this->tmpDir . '/.system/settings.json', (string) json_encode([
		'sync' => ['url' => '', 'key' => 'test'],
	]));

	$app     = new Application();
	$command = new PullCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
});

it('errors when sync key is empty', function (): void {
	file_put_contents($this->tmpDir . '/.system/settings.json', (string) json_encode([
		'sync' => ['url' => 'https://example.com', 'key' => ''],
	]));

	$app     = new Application();
	$command = new PullCommand($this->totalcms);
	$app->addCommand($command);
	$tester = new CommandTester($command);

	$tester->execute([]);

	expect($tester->getStatusCode())->toBe(1);
});
