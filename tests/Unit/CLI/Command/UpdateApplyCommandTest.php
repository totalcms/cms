<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\UpdateApplyCommand;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Domain\Update\Service\UpdateDownloader;
use TotalCMS\TotalCMS;

describe('update:apply', function (): void {
	it('reports already up to date', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: false,
			version: '3.2.2',
			releaseDate: '',
			severity: '',
			changelog: '',
			buildHash: '',
			downloadUrl: ''
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('up to date');
		expect($tester->getStatusCode())->toBe(0);
	});

	it('blocks when updates expired', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/download/3.3.0',
			updatesValid: false,
			updatesExpireDate: '2025-01-01'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('expired');
		expect($tester->getStatusCode())->toBe(1);
	});

	it('downloads and applies with --force', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: 'New features',
			buildHash: 'abc',
			downloadUrl: '/download/3.3.0'
		));
		$checker->expects($this->once())->method('clearCache');
		$totalcms->method('updateChecker')->willReturn($checker);

		$downloader = $this->createMock(UpdateDownloader::class);
		$downloader->expects($this->once())->method('download')
			->with('3.3.0', '/download/3.3.0')
			->willReturn('/tmp/update-3.3.0.zip');
		$totalcms->method('updateDownloader')->willReturn($downloader);

		$applier = $this->createMock(UpdateApplier::class);
		$applier->expects($this->once())->method('apply')
			->with('/tmp/update-3.3.0.zip', '3.3.0');
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('3.3.0');
		expect($tester->getDisplay())->toContain('successfully');
		expect($tester->getStatusCode())->toBe(0);
	});

	it('outputs JSON on success', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/download/3.3.0'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$downloader = $this->createMock(UpdateDownloader::class);
		$downloader->method('download')->willReturn('/tmp/update.zip');
		$totalcms->method('updateDownloader')->willReturn($downloader);

		$applier = $this->createMock(UpdateApplier::class);
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['success'])->toBeTrue();
		expect($data['version'])->toBe('3.3.0');
	});

	it('handles download failure', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/download/3.3.0'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$downloader = $this->createMock(UpdateDownloader::class);
		$downloader->method('download')->willThrowException(new \RuntimeException('Network error'));
		$totalcms->method('updateDownloader')->willReturn($downloader);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('Network error');
		expect($tester->getStatusCode())->toBe(1);
	});

	it('handles apply failure', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$checker = $this->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(new UpdateInfo(
			available: true,
			version: '3.3.0',
			releaseDate: '2026-04-10',
			severity: 'minor',
			changelog: '',
			buildHash: '',
			downloadUrl: '/download/3.3.0'
		));
		$totalcms->method('updateChecker')->willReturn($checker);

		$downloader = $this->createMock(UpdateDownloader::class);
		$downloader->method('download')->willReturn('/tmp/update.zip');
		$totalcms->method('updateDownloader')->willReturn($downloader);

		$applier = $this->createMock(UpdateApplier::class);
		$applier->method('apply')->willThrowException(new \RuntimeException('Disk full'));
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateApplyCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('Disk full');
		expect($tester->getStatusCode())->toBe(1);
	});
});
