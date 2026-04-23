<?php

use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Service\TwigExtensionRegistrar;
use Twig\TwigFilter;
use Twig\TwigFunction;

function createTestLogger(): object
{
	return new class extends Psr\Log\AbstractLogger {
		/** @var list<string> */
		public array $messages = [];

		public function log($level, Stringable|string $message, array $context = []): void
		{
			$this->messages[] = "[{$level}] {$message}";
		}
	};
}

describe('TwigExtensionRegistrar', function (): void {
	test('passes through functions with no collisions', function (): void {
		$filter = new TwigExtensionRegistrar(new NullLogger());

		$result = $filter->filter(
			[new TwigFunction('ext_hello', fn (): string => 'hello')],
			[],
			[],
			['selectOptions', 'embed'],
			[],
			[],
		);

		expect($result['functions'])->toHaveCount(1);
		expect($result['functions'][0]->getName())->toBe('ext_hello');
	});

	test('blocks functions that collide with core', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[
				new TwigFunction('selectOptions', fn (): array => []),
				new TwigFunction('ext_safe', fn (): string => 'safe'),
			],
			[],
			[],
			['selectOptions', 'embed', 'next', 'prev'],
			[],
			[],
		);

		expect($result['functions'])->toHaveCount(1);
		expect($result['functions'][0]->getName())->toBe('ext_safe');
		expect($logger->messages)->toHaveCount(1);
		expect($logger->messages[0])->toContain('selectOptions');
		expect($logger->messages[0])->toContain('blocked');
	});

	test('blocks filters that collide with core', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[],
			[
				new TwigFilter('truncate', fn (string $v): string => $v),
				new TwigFilter('ext_shout', fn (string $v) => strtoupper($v)),
			],
			[],
			[],
			['truncate', 'dateFormat', 'sortBy'],
			[],
		);

		expect($result['filters'])->toHaveCount(1);
		expect($result['filters'][0]->getName())->toBe('ext_shout');
		expect($logger->messages[0])->toContain('truncate');
		expect($logger->messages[0])->toContain('blocked');
	});

	test('blocks globals that collide with core', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[],
			[],
			['cms' => 'evil', 'myGlobal' => 'safe'],
			[],
			[],
			['cms', 'getData', 'postData', 'sessionData', 'patterns'],
		);

		expect($result['globals'])->toBe(['myGlobal' => 'safe']);
		expect($logger->messages[0])->toContain('cms');
		expect($logger->messages[0])->toContain('blocked');
	});

	test('warns on extension-to-extension function collision but allows it', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[
				new TwigFunction('shared_name', fn (): string => 'first'),
				new TwigFunction('shared_name', fn (): string => 'second'),
			],
			[],
			[],
			[],
			[],
			[],
		);

		// Both pass through — last one wins when Twig registers them
		expect($result['functions'])->toHaveCount(2);
		expect($logger->messages)->toHaveCount(1);
		expect($logger->messages[0])->toContain('shared_name');
		expect($logger->messages[0])->toContain('multiple extensions');
	});

	test('warns on extension-to-extension filter collision but allows it', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[],
			[
				new TwigFilter('shared_filter', fn (string $v): string => $v),
				new TwigFilter('shared_filter', fn (string $v): string => $v),
			],
			[],
			[],
			[],
			[],
		);

		expect($result['filters'])->toHaveCount(2);
		expect($logger->messages)->toHaveCount(1);
		expect($logger->messages[0])->toContain('shared_filter');
	});

	test('handles empty inputs', function (): void {
		$filter = new TwigExtensionRegistrar(new NullLogger());

		$result = $filter->filter([], [], [], [], [], []);

		expect($result['functions'])->toBe([]);
		expect($result['filters'])->toBe([]);
		expect($result['globals'])->toBe([]);
	});

	test('multiple collision types in one call', function (): void {
		$logger = createTestLogger();
		$filter = new TwigExtensionRegistrar($logger);

		$result = $filter->filter(
			[
				new TwigFunction('embed', fn (): string => ''),
				new TwigFunction('ext_ok', fn (): string => ''),
			],
			[
				new TwigFilter('truncate', fn (string $v): string => $v),
				new TwigFilter('ext_ok', fn (string $v): string => $v),
			],
			['cms' => 'bad', 'extVar' => 'good'],
			['embed', 'selectOptions'],
			['truncate', 'dateFormat'],
			['cms', 'getData'],
		);

		expect($result['functions'])->toHaveCount(1);
		expect($result['functions'][0]->getName())->toBe('ext_ok');
		expect($result['filters'])->toHaveCount(1);
		expect($result['filters'][0]->getName())->toBe('ext_ok');
		expect($result['globals'])->toBe(['extVar' => 'good']);
		expect($logger->messages)->toHaveCount(3);
	});
});
