<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Integration tests for the needsAttention and automationsWidget Twig macros.
 *
 * Uses TwigEngine::renderString() — the same pattern as JobQueueHealthDashboardTest —
 * so macro markup, t() calls, and variable bindings are all exercised through the
 * real Twig environment.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

// ---------------------------------------------------------------------------
// needsAttention macro
// ---------------------------------------------------------------------------

it('needsAttention renders nothing when alerts list is empty', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$html = trim((string)$twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.needsAttention(alerts) }}",
		['alerts' => []],
	));

	expect($html)->toBe('');
});

it('needsAttention renders a warning-box for each alert', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$alerts = [
		['level' => 'warning', 'message' => 'Something needs attention', 'link' => null, 'linkText' => null],
		['level' => 'error',   'message' => 'Critical problem detected',  'link' => null, 'linkText' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.needsAttention(alerts) }}",
		['alerts' => $alerts],
	);

	expect($html)
		->toContain('warning-box')
		->toContain('Something needs attention')
		->toContain('Critical problem detected');
});

it('needsAttention renders a link when provided', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$alerts = [
		['level' => 'info', 'message' => 'An update is available', 'link' => 'utils/update', 'linkText' => 'Update now'],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.needsAttention(alerts) }}",
		['alerts' => $alerts],
	);

	expect($html)
		->toContain('utils/update')
		->toContain('Update now');
});

it('needsAttention applies level-specific CSS class', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$alerts = [
		['level' => 'error', 'message' => 'Error alert', 'link' => null, 'linkText' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.needsAttention(alerts) }}",
		['alerts' => $alerts],
	);

	expect($html)->toContain('warning-box--error');
});

// ---------------------------------------------------------------------------
// automationsWidget macro
// ---------------------------------------------------------------------------

it('automationsWidget renders a row per automation', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$automations = [
		['id' => 'send-digest',  'name' => 'Send Digest',  'trigger' => 'schedule', 'enabled' => true,  'lastResult' => 'success', 'lastRunAt' => mktime(10, 0, 0, 6, 1, 2026), 'nextRunAt' => null],
		['id' => 'clean-images', 'name' => 'Clean Images', 'trigger' => 'webhook',  'enabled' => false, 'lastResult' => 'failed',  'lastRunAt' => mktime(9, 0, 0, 6, 1, 2026), 'nextRunAt' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.automationsWidget(automations) }}",
		['automations' => $automations],
	);

	expect($html)
		->toContain('Send Digest')
		->toContain('Clean Images')
		->toContain('schedule')
		->toContain('webhook');
});

it('automationsWidget emphasises failed result', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$automations = [
		['id' => 'bad-job', 'name' => 'Bad Job', 'trigger' => 'schedule', 'enabled' => true, 'lastResult' => 'failed', 'lastRunAt' => time(), 'nextRunAt' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.automationsWidget(automations) }}",
		['automations' => $automations],
	);

	expect($html)
		->toContain('automation-result--failed')
		->toContain('✗');
});

it('automationsWidget shows success indicator for successful automations', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$automations = [
		['id' => 'good-job', 'name' => 'Good Job', 'trigger' => 'schedule', 'enabled' => true, 'lastResult' => 'success', 'lastRunAt' => time(), 'nextRunAt' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.automationsWidget(automations) }}",
		['automations' => $automations],
	);

	expect($html)
		->toContain('automation-result--success')
		->toContain('✓');
});

it('automationsWidget shows never-run text when lastResult is null', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$automations = [
		['id' => 'new-job', 'name' => 'New Job', 'trigger' => 'webhook', 'enabled' => true, 'lastResult' => null, 'lastRunAt' => null, 'nextRunAt' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.automationsWidget(automations) }}",
		['automations' => $automations],
	);

	expect($html)->toContain('automation-never-run');
});

it('automationsWidget includes a view-all link to automations page', function (): void {
	$twig = $this->app->getContainer()->get(TwigEngine::class);

	$automations = [
		['id' => 'any', 'name' => 'Any', 'trigger' => 'schedule', 'enabled' => true, 'lastResult' => null, 'lastRunAt' => null, 'nextRunAt' => null],
	];

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.automationsWidget(automations) }}",
		['automations' => $automations],
	);

	expect($html)->toContain('href="automations"');
});
