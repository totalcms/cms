<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/**
 * Browser error reporting used to live only in the two built-in layouts. A
 * customer admin page (the Stacks Admin Core, or any page calling
 * cms.adminAssetsHead()) ran the same admin scripts with nothing listening,
 * so an error in the form code there was never reported. The helper now
 * emits the snippet, limited to errors thrown by Total CMS's own scripts and
 * dashboard pages so the customer's scripts stay out of the project.
 */
beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->config  = $this->app->getContainer()->get(Config::class);
	$this->adapter = $this->app->getContainer()->get(TotalCMSTwigAdapter::class);
});

it('emits the Sentry snippet from adminAssetsHead when the setting is on', function (): void {
	$this->config->sentry = true;

	$head = $this->adapter->adminAssetsHead();

	expect(substr_count($head, 'js.sentry-cdn.com'))->toBe(1)
		->and($head)->toContain('Sentry.init(');
});

it('limits reports to Total CMS scripts and dashboard pages', function (): void {
	$this->config->sentry = true;

	$head = $this->adapter->adminAssetsHead();

	expect($head)->toContain('allowUrls')
		->and($head)->toContain(json_encode($this->adapter->api . '/assets/', JSON_UNESCAPED_SLASHES))
		->and($head)->toContain(json_encode($this->adapter->dashboard . '/', JSON_UNESCAPED_SLASHES));
});

it('emits nothing when the setting is off', function (): void {
	$this->config->sentry = false;

	expect($this->adapter->adminAssetsHead())->not->toContain('sentry');
});

// These two read the templates instead of requesting the pages: a request with
// the setting on would also start the PHP Sentry SDK inside the test run.
it('is not included a second time by the dashboard layout', function (): void {
	$layout = (string)file_get_contents(dirname(__DIR__, 2) . '/resources/templates/admin-dashboard.twig');

	expect($layout)->toContain('cms.adminAssetsHead()')
		->and($layout)->not->toContain("include 'partials/sentry.twig'");
});

it('stays on the login and setup layout, without the script filter', function (): void {
	$this->config->sentry = true;
	$layout = (string)file_get_contents(dirname(__DIR__, 2) . '/resources/templates/admin-layout.twig');

	// That layout writes its own asset tags and never calls the helper.
	expect($layout)->toContain("include 'partials/sentry.twig'")
		->and($layout)->not->toContain('{{ cms.adminAssetsHead() }}');

	$snippet = $this->app->getContainer()->get(TwigEngine::class)->render('partials/sentry.twig');

	expect(substr_count($snippet, 'js.sentry-cdn.com'))->toBe(1)
		->and($snippet)->not->toContain('allowUrls');
});
