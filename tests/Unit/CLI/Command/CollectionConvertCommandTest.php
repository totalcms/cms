<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Command;

use DI\Container;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\CollectionConvertCommand;
use TotalCMS\Domain\Collection\Service\CollectionFormatConverter;
use TotalCMS\Domain\Collection\Service\ConversionReport;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	$this->converter = $this->createMock(CollectionFormatConverter::class);
	$container       = $this->createMock(Container::class);
	$container->method('get')->with(CollectionFormatConverter::class)->willReturn($this->converter);
	$this->totalcms = $this->createMock(TotalCMS::class);
	$this->totalcms->method('container')->willReturn($container);

	$app = new Application();
	$app->add(new CollectionConvertCommand($this->totalcms));
	$this->tester = new CommandTester($app->find('collection:convert'));
});

test('converts and reports a clean run as success', function (): void {
	$this->converter->expects($this->once())->method('convert')->with('docs', 'markdown', false)
		->willReturn(new ConversionReport('docs', 'json', 'markdown', 12, 0, [], false));

	$this->tester->execute(['collection' => 'docs', '--to' => 'markdown']);

	expect($this->tester->getStatusCode())->toBe(0);
	expect($this->tester->getDisplay())->toContain('12')->toContain('markdown');
});

test('a failed object fails the command and says whether it could not be read or written', function (): void {
	$this->converter->expects($this->once())->method('convert')->with('docs', 'markdown', false)
		->willReturn(new ConversionReport('docs', 'json', 'markdown', 12, 0, ['bad' => 'read', 'worse' => 'write'], false));

	$this->tester->execute(['collection' => 'docs', '--to' => 'markdown']);

	expect($this->tester->getStatusCode())->not->toBe(0);
	expect($this->tester->getDisplay())
		->toContain('12')->toContain('markdown')
		->toContain("Could not read 'bad'")
		->toContain("Could not write 'worse'");
});

test('dry run passes the flag and says so', function (): void {
	$this->converter->method('convert')->with('docs', 'json', true)
		->willReturn(new ConversionReport('docs', 'markdown', 'json', 3, 0, [], true));

	$this->tester->execute(['collection' => 'docs', '--to' => 'json', '--dry-run' => true]);
	expect($this->tester->getDisplay())->toContain('dry run');
});

test('json output', function (): void {
	$this->converter->method('convert')->willReturn(new ConversionReport('docs', 'json', 'markdown', 1, 2, [], false));
	$this->tester->execute(['collection' => 'docs', '--to' => 'markdown', '--json' => true]);
	expect(json_decode($this->tester->getDisplay(), true))->toMatchArray(['collection' => 'docs', 'to' => 'markdown', 'converted' => 1, 'skipped' => 2]);
	expect($this->tester->getStatusCode())->toBe(0);
});

test('json output exits non-zero when an object failed', function (): void {
	$this->converter->method('convert')->willReturn(new ConversionReport('docs', 'json', 'markdown', 1, 0, ['bad' => 'read'], false));
	$this->tester->execute(['collection' => 'docs', '--to' => 'markdown', '--json' => true]);

	expect($this->tester->getStatusCode())->not->toBe(0);
	expect(json_decode($this->tester->getDisplay(), true))->toMatchArray(['collection' => 'docs', 'failed' => ['bad' => 'read']]);
});

test('already in the target format reports nothing to do', function (): void {
	$this->converter->method('convert')->willReturn(new ConversionReport('docs', 'markdown', 'markdown', 0, 3, [], false));
	$this->tester->execute(['collection' => 'docs', '--to' => 'markdown']);
	expect($this->tester->getDisplay())->toContain('already stored as markdown')->toContain('nothing to do');
});

test('errors are reported, not thrown', function (): void {
	$this->converter->method('convert')->willThrowException(new \DomainException('Unknown storage format'));
	$this->tester->execute(['collection' => 'docs', '--to' => 'toml']);
	expect($this->tester->getStatusCode())->not->toBe(0)->and($this->tester->getDisplay())->toContain('Unknown storage format');
});
