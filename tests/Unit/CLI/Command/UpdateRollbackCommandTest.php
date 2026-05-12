<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\UpdateRollbackCommand;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\TotalCMS;

describe('update:rollback', function (): void {
	it('rolls back with --force', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$applier = $this->createMock(UpdateApplier::class);
		$applier->expects($this->once())->method('rollback');
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateRollbackCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('Rollback complete');
		expect($tester->getStatusCode())->toBe(0);
	});

	it('outputs JSON on success', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$applier = $this->createMock(UpdateApplier::class);
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateRollbackCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--json' => true]);

		$data = json_decode($tester->getDisplay(), true);
		expect($data['success'])->toBeTrue();
		expect($data['message'])->toContain('Rollback complete');
	});

	it('handles rollback failure', function (): void {
		$totalcms = $this->createMock(TotalCMS::class);

		$applier = $this->createMock(UpdateApplier::class);
		$applier->method('rollback')->willThrowException(new \RuntimeException('No backup found'));
		$totalcms->method('updateApplier')->willReturn($applier);

		$app     = new Application();
		$command = new UpdateRollbackCommand($totalcms);
		$app->addCommand($command);
		$tester = new CommandTester($command);

		$tester->execute(['--force' => true]);

		expect($tester->getDisplay())->toContain('No backup found');
		expect($tester->getStatusCode())->toBe(1);
	});
});
