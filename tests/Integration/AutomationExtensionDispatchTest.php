<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRegistry;
use TotalCMS\Domain\Automation\Service\AutomationResolver;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Data\AutomationDefinition;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

it('merges registered extension automations into the dispatch list and runs them', function (): void {
	$container = $this->app->getContainer();

	$ran = new ArrayObject(['count' => 0]);
	$container->get(AutomationRegistry::class)->register(
		'test-vendor/ext:beat',
		new AutomationDefinition(
			'beat',
			'Heartbeat',
			[['type' => 'event', 'event' => 'object.created']],
			function ($ctx) use ($ran): string {
				$ran['count']++;

				return 'beat';
			},
		),
	);

	$loader = $container->get(AutomationLoader::class);

	// all() exposes the extension automation, tagged isExtension.
	$descriptor = collect($loader->all())->firstWhere('id', 'test-vendor/ext:beat');
	expect($descriptor)->not->toBeNull();
	expect($descriptor->isExtension)->toBeTrue();

	// handler() resolves the in-memory closure for a colon-keyed id.
	expect($loader->handler('test-vendor/ext:beat'))->toBeCallable();

	// the event resolver matches it.
	$matches = $container->get(AutomationResolver::class)->eventTriggers('object.created', 'blog');
	expect(collect($matches)->pluck('id')->all())->toContain('test-vendor/ext:beat');

	// the runner executes the closure and records success.
	$record = $container->get(AutomationRunner::class)
		->run('test-vendor/ext:beat', ['type' => 'event'], [], null, ['event' => 'object.created']);

	expect($record->status)->toBe('success');
	expect($record->return)->toBe('beat');
	expect($ran['count'])->toBe(1);
});

it('does not attempt to disable a failing extension automation', function (): void {
	$container = $this->app->getContainer();

	$container->get(AutomationRegistry::class)->register(
		'test-vendor/ext:bad',
		new AutomationDefinition('bad', 'Bad', [['type' => 'event', 'event' => 'object.created']], function ($ctx): void {
			throw new RuntimeException('boom');
		}),
	);

	// Repeated failures must not throw (disable() would 404 on a non-existent
	// collection record — the runner skips it for colon-keyed ids).
	$runner = $container->get(AutomationRunner::class);
	for ($i = 0; $i < 3; $i++) {
		$record = $runner->run('test-vendor/ext:bad', ['type' => 'event'], [], null, ['event' => 'object.created']);
		expect($record->status)->toBe('failed');
	}
});
