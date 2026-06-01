<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Service\AutomationResolver;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

it('finds the webhook trigger by automation id and lists matching event triggers', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'multi',
		'name'     => 'Multi',
		'enabled'  => true,
		'triggers' => [
			't0' => ['id' => 't0', 'type' => 'webhook', 'auth' => 'apiKey', 'sync' => false],
			't1' => ['id' => 't1', 'type' => 'event', 'event' => 'object.created', 'collection' => 'orders'],
		],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return true; };\n",
	]);

	$resolver = $container->get(AutomationResolver::class);

	$webhook = $resolver->webhook('multi');
	expect($webhook)->not->toBeNull();
	expect($webhook['slug'])->toBe('multi');
	expect($webhook['trigger']['auth'])->toBe('apiKey');

	expect($resolver->webhook('does-not-exist'))->toBeNull();

	$matches = $resolver->eventTriggers('object.created', 'orders');
	expect($matches)->toHaveCount(1);
	expect($matches[0]['slug'])->toBe('multi');

	// Collection filter excludes other collections.
	expect($resolver->eventTriggers('object.created', 'posts'))->toBeEmpty();
	// Different event name does not match.
	expect($resolver->eventTriggers('object.deleted', 'orders'))->toBeEmpty();
});
