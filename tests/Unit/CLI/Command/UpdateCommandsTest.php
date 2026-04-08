<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\UpdateCheckCommand;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\TotalCMS;

describe('update:check', function (): void {
	it('shows available update', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true, version: '3.3.0', releaseDate: '2026-04-10',
			severity: 'minor', changelog: 'New features', buildHash: 'abc', downloadUrl: '/download/3.3.0'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateCheckCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute([]);

		$output = $tester->getDisplay();
		expect($output)->toContain('3.3.0');
		expect($output)->toContain('minor');
	});

	it('shows up to date message', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: false, version: '3.2.2', releaseDate: '',
			severity: '', changelog: '', buildHash: '', downloadUrl: ''
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateCheckCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute([]);

		expect($tester->getDisplay())->toContain('up to date');
	});

	it('outputs JSON with --json', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true, version: '3.3.0', releaseDate: '2026-04-10',
			severity: 'minor', changelog: '', buildHash: '', downloadUrl: '/download/3.3.0'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateCheckCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['available'])->toBeTrue();
		expect($data['version'])->toBe('3.3.0');
	});

	it('handles check failure', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willThrowException(new \RuntimeException('Network error'));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateCheckCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute([]);

		expect($tester->getStatusCode())->toBe(1);
	});
});
