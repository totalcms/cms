<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CacheClearCommand;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->totalcms = $this->createMock(TotalCMS::class);
	$this->totalcms->method('clearCache')->willReturn([
		'filesystem' => ['cleared' => true],
		'opcache'    => ['cleared' => false],
		'redis'      => ['cleared' => true],
		'success'    => true,
	]);

	$app     = new Application();
	$command = new CacheClearCommand($this->totalcms);
	$app->addCommand($command);
	$this->tester = new CommandTester($command);
});

it('clears cache and shows results', function (): void {
	$this->tester->execute([]);

	$output = $this->tester->getDisplay();
	expect($output)->toContain('Cache cleared');
	expect($output)->toContain('filesystem');
	expect($this->tester->getStatusCode())->toBe(0);
});

it('outputs JSON with --json', function (): void {
	$this->tester->execute(['--json' => true]);

	$data = json_decode($this->tester->getDisplay(), true);
	expect($data)->toBeArray();
	expect($data['filesystem'])->toHaveKey('cleared');
	expect($data['filesystem']['cleared'])->toBeTrue();
});
