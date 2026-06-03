<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\AutomationsProcessCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

function makeAutomationsCommand(object $container): AutomationsProcessCommand
{
	$totalcms = test()->createMock(TotalCMS::class);
	$totalcms->method('container')->willReturn($container);
	$totalcms->config = $container->get(Config::class);

	return new AutomationsProcessCommand($totalcms);
}

it('drains a queued async run on the next tick', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'queued',
		'name'     => 'Queued',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'webhook', 'auth' => 'none']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return \$ctx->args; };\n",
	]);
	$container->get(\TotalCMS\Domain\Automation\Service\AutomationQueue::class)
		->enqueue('queued', ['type' => 'webhook'], ['hello' => 'world']);

	$tester = new CommandTester(makeAutomationsCommand($container));
	$tester->execute(['--json' => true]);

	expect($tester->getStatusCode())->toBe(0);
	expect((int)(json_decode($tester->getDisplay(), true)['drained'] ?? 0))->toBe(1);

	$runs = glob(cmsDataDir() . '.system/automations/queued/runs/*.json');
	expect($runs)->not->toBeEmpty();
	$record = json_decode((string)file_get_contents($runs[0]), true);
	expect($record['return'])->toBe(['hello' => 'world']);
});

it('fires a due schedule automation and writes a run record', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'minutely',
		'name'     => 'Minutely',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return ['ok' => 1]; };\n",
	]);

	$tester = new CommandTester(makeAutomationsCommand($container));
	$tester->execute(['--json' => true]);

	expect($tester->getStatusCode())->toBe(0);
	expect(glob(cmsDataDir() . '.system/automations/minutely/runs/*.json'))->not->toBeEmpty();

	$result = json_decode($tester->getDisplay(), true);
	expect($result['fired'])->toContain('minutely');
});

it('does not re-fire a schedule already fired this minute', function (): void {
	$container = $this->app->getContainer();
	$container->get(ObjectSaver::class)->saveObject('automations', [
		'id'       => 'minutely',
		'name'     => 'Minutely',
		'enabled'  => true,
		'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
		'handler'  => "<?php\n\nreturn function (\$ctx) { return 1; };\n",
	]);

	(new CommandTester(makeAutomationsCommand($container)))->execute([]);
	$afterFirst = count(glob(cmsDataDir() . '.system/automations/minutely/runs/*.json'));

	(new CommandTester(makeAutomationsCommand($container)))->execute([]);
	$afterSecond = count(glob(cmsDataDir() . '.system/automations/minutely/runs/*.json'));

	expect($afterFirst)->toBe(1);
	expect($afterSecond)->toBe(1); // same minute → not due again
});
