<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Cron\CronAutomationsAction;
use TotalCMS\Domain\Automation\Service\AutomationQueue;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;
use TotalCMS\Support\ProcessLock;

// The cron URL runs the same AutomationTicker as `tcms automations:process`,
// behind the same lock file. Pinned here so the two can no longer drift.
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

function cronTick(object $container): array
{
	$action   = $container->get(CronAutomationsAction::class);
	$response = $action((new ServerRequestFactory())->createServerRequest('GET', '/cron/automations'), new Response());

	expect($response->getStatusCode())->toBe(200);

	return (array)json_decode((string)$response->getBody(), true);
}

it('drains the queue and fires due schedules over HTTP', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'minutely',
		'name'     => 'Minutely',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return ['ok' => 1]; };\n",
	]);
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'queued',
		'name'     => 'Queued',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'webhook', 'auth' => 'none']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return \$ctx->args; };\n",
	]);
	$container->get(AutomationQueue::class)->enqueue('queued', ['type' => 'webhook'], ['hello' => 'world']);

	$result = cronTick($container);

	expect($result['fired'])->toBe(['minutely'])
		->and($result['count'])->toBe(1)
		->and($result['drained'])->toBe(1)
		->and(glob(cmsDataDir() . '.system/automations/queued/runs/*.json'))->not->toBeEmpty();
});

it('reports already-running while another tick holds the lock', function (): void {
	$container = $this->app->getContainer();
	$lock      = ProcessLock::open($container->get(Config::class)->datadir . '/.system/.processAutomations.lock');
	expect($lock->acquire())->toBeTrue();

	try {
		expect(cronTick($container))->toBe(['skipped' => 'already-running']);
	} finally {
		$lock->release();
	}
});
