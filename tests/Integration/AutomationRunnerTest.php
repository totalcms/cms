<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Service\AutomationActivityLogger;
use TotalCMS\Domain\Automation\Service\AutomationGuard;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

function saveAutomation(object $container, string $id, string $handler, bool $enabled = true): void
{
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => $id,
		'name'     => ucfirst($id),
		'enabled'  => $enabled,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '0 1 * * *']],
		'handler'  => $handler,
	]);
}

it('lists enabled automations and resolves the handler closure', function (): void {
	$container = $this->app->getContainer();
	saveAutomation($container, 'daily', "<?php\n\nreturn function (\$ctx) {\n    return ['ran' => true];\n};\n");
	saveAutomation($container, 'off', "<?php\n\nreturn function (\$ctx) { return 1; };\n", enabled: false);

	$loader = $container->get(AutomationLoader::class);

	$enabled = $loader->enabled();
	expect($enabled)->toHaveCount(1);
	expect($enabled[0]->id)->toBe('daily');

	$fn = $loader->handler('daily');
	expect($fn)->toBeCallable();
	expect(($fn)(null))->toBe(['ran' => true]);
});

it('runs a handler, returns its value, and writes a success run record', function (): void {
	$container = $this->app->getContainer();
	saveAutomation($container, 'daily', "<?php\n\nreturn function (\$ctx) {\n    return ['created' => 7];\n};\n");

	$record = $container->get(AutomationRunner::class)->run('daily', ['type' => 'schedule'], []);

	expect($record->status)->toBe('success');
	expect($record->return)->toBe(['created' => 7]);
	expect(glob(cmsDataDir() . '.system/automations/daily/runs/*.json'))->toHaveCount(1);
});

it('captures a thrown handler as a failed run record and increments failures', function (): void {
	$container = $this->app->getContainer();
	saveAutomation($container, 'boom', "<?php\n\nreturn function (\$ctx) {\n    throw new \\RuntimeException('nope');\n};\n");

	$record = $container->get(AutomationRunner::class)->run('boom', ['type' => 'schedule'], []);

	expect($record->status)->toBe('failed');
	expect($record->exception)->toContain('nope');
	expect($container->get(AutomationStateStore::class)->failures('boom'))->toBe(1);
});

/**
 * Build a runner whose guard believes it is running in Production, so the
 * auto-disable breaker actually trips. Everything else is the real container
 * wiring.
 */
function prodRunner(object $container): AutomationRunner
{
	$prodConfig      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$prodConfig->env = 'prod';
	$guard           = new AutomationGuard(new EnvironmentResolver($prodConfig, false), $container->get(CacheManager::class));

	return new AutomationRunner(
		$container->get(AutomationLoader::class),
		$container->get(AutomationStateStore::class),
		$container->get(StorageAdapterInterface::class),
		$container->get(IndexReader::class),
		$container->get(ObjectFetcher::class),
		$container->get(ObjectSaver::class),
		$container->get(ObjectUpdater::class),
		$container->get(ObjectRemover::class),
		$container->get(EmailService::class),
		$container->get(Config::class),
		$guard,
		$container->get(AutomationActivityLogger::class),
		$container->get(LoggerFactory::class),
	);
}

it('auto-disables an automation after repeated Production failures', function (): void {
	$container = $this->app->getContainer();
	$container->get(CacheManager::class)->clearData('auto_fail_' . md5('breaker'));
	saveAutomation($container, 'breaker', "<?php\n\nreturn function (\$ctx) {\n    throw new \\RuntimeException('always');\n};\n");

	$runner = prodRunner($container);

	// Threshold is 5: the first four failures keep it enabled.
	for ($i = 0; $i < 4; $i++) {
		$runner->run('breaker', ['type' => 'schedule'], []);
	}
	expect($container->get(ObjectFetcher::class)->fetchObject('automations', 'breaker')->toArray()['enabled'])->toBeTrue();

	// The fifth trips the breaker.
	$runner->run('breaker', ['type' => 'schedule'], []);
	expect($container->get(ObjectFetcher::class)->fetchObject('automations', 'breaker')->toArray()['enabled'])->toBeFalse();
});

it('never auto-disables in development no matter how many times it fails', function (): void {
	$container = $this->app->getContainer();
	saveAutomation($container, 'devboom', "<?php\n\nreturn function (\$ctx) {\n    throw new \\RuntimeException('still here');\n};\n");

	$runner = $container->get(AutomationRunner::class); // dev/test environment

	for ($i = 0; $i < 8; $i++) {
		$runner->run('devboom', ['type' => 'schedule'], []);
	}

	expect($container->get(ObjectFetcher::class)->fetchObject('automations', 'devboom')->toArray()['enabled'])->toBeTrue();
});
