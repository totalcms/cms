<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
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
