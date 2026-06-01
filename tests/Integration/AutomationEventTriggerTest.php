<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

function createOrdersCollection(object $container): void
{
	$container->get(SchemaSaver::class)->saveSchema([
		'id'         => 'orders',
		'description' => 'Orders',
		'properties' => [
			'id'    => ['type' => 'string', 'field' => 'text'],
			'total' => ['type' => 'number', 'field' => 'number'],
		],
		'required' => [],
		'index'    => ['id'],
	]);

	$collection                     = new CollectionData();
	$collection->id                 = 'orders';
	$collection->name               = 'Orders';
	$collection->schema             = 'orders';
	$collection->description        = 'Orders';
	$collection->url                = '';
	$collection->category           = '';
	$collection->labelPlural        = '';
	$collection->labelSingular      = '';
	$collection->groups             = [];
	$collection->sortBy             = 'id';
	$collection->reverseSort        = false;
	$collection->prettyUrl          = false;
	$collection->queueRebuildOnSave = false;

	$container->get(CollectionRepository::class)->saveCollection($collection);
}

it('enqueues an automation run when a matching object.created event fires', function (): void {
	$container = $this->app->getContainer();
	createOrdersCollection($container);

	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'on-order',
		'name'     => 'On order',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'event', 'event' => 'object.created', 'collection' => 'orders']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return \$ctx->event['collection']; };\n",
	]);

	// Saving an order dispatches object.created → the subscriber enqueues a run.
	$container->get(ObjectSaver::class)->saveObject('orders', ['id' => 'o1', 'total' => 10]);

	$pending = glob(cmsDataDir() . '.system/automations/_queue/*.json');
	expect($pending)->not->toBeEmpty();

	$job = json_decode((string)file_get_contents($pending[0]), true);
	expect($job['id'])->toBe('on-order');
	expect($job['event']['collection'])->toBe('orders');
	expect($job['event']['object']['total'])->toBe(10); // payload snapshot carries the object
});

it('runs the drained event handler with $ctx->event populated end-to-end', function (): void {
	$container = $this->app->getContainer();
	createOrdersCollection($container);

	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'on-order',
		'name'     => 'On order',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'event', 'event' => 'object.created', 'collection' => 'orders']],
		'handler'  => "<?php\n\nreturn function (\$ctx) {\n    return ['collection' => \$ctx->event['collection'], 'total' => \$ctx->event['object']['total']];\n};\n",
	]);

	// Dispatch (save an order) → enqueue → drain → run.
	$container->get(ObjectSaver::class)->saveObject('orders', ['id' => 'o9', 'total' => 99]);

	$runner = $container->get(\TotalCMS\Domain\Automation\Service\AutomationRunner::class);
	$container->get(\TotalCMS\Domain\Automation\Service\AutomationQueue::class)->drain(function (array $job) use ($runner): void {
		$runner->run((string)$job['id'], $job['trigger'], $job['args'], null, $job['event']);
	});

	$runs = glob(cmsDataDir() . '.system/automations/on-order/runs/*.json');
	expect($runs)->not->toBeEmpty();
	$record = json_decode((string)file_get_contents($runs[0]), true);
	expect($record['status'])->toBe('success');
	expect($record['return'])->toBe(['collection' => 'orders', 'total' => 99]);
});

it('does not enqueue for a non-matching collection filter', function (): void {
	$container = $this->app->getContainer();
	createOrdersCollection($container);

	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'on-posts',
		'name'     => 'On posts',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'event', 'event' => 'object.created', 'collection' => 'posts']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return true; };\n",
	]);

	$container->get(ObjectSaver::class)->saveObject('orders', ['id' => 'o1', 'total' => 5]);

	expect(glob(cmsDataDir() . '.system/automations/_queue/*.json'))->toBeEmpty();
});
