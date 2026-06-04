<?php

declare(strict_types=1);

use TotalCMS\Domain\JobQueue\Data\JobQueueHealthData;
use TotalCMS\Domain\JobQueue\Service\JobQueueHealth;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Service\TwigEngine;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

// Wiring guard: AdminTwigAdapter gained a JobQueueHealth dependency, and the
// dashboard + Job Queue Manager templates call cms.admin.dashboardJobQueueHealth().
// If the container can't build the adapter, the whole admin 500s — so resolve it
// through the real container, not a mock.
it('resolves AdminTwigAdapter with the JobQueueHealth dependency', function (): void {
	expect($this->app->getContainer()->get(AdminTwigAdapter::class))
		->toBeInstanceOf(AdminTwigAdapter::class);
});

it('reports a healthy (not stalled) queue end-to-end with an empty queue', function (): void {
	$health = $this->app->getContainer()->get(JobQueueHealth::class)->status();

	expect($health)->toBeInstanceOf(JobQueueHealthData::class);
	expect($health->stalled)->toBeFalse();
	expect($health->pendingCount)->toBe(0);
});

it('exposes the same health via the admin adapter accessor', function (): void {
	$health = $this->app->getContainer()->get(AdminTwigAdapter::class)->dashboardJobQueueHealth();

	expect($health)->toBeInstanceOf(JobQueueHealthData::class);
	expect($health->stalled)->toBeFalse();
});

// Render the warning macro through the real Twig env so the markup, t() keys, and
// cms.admin.processJobQueueCommand() access are all exercised (the stalled branch
// only shows when a queue is actually stalled, so it's easy to miss otherwise).
it('renders the stalled banner with the cron command', function (): void {
	$twig   = $this->app->getContainer()->get(TwigEngine::class);
	$health = new JobQueueHealthData(stalled: true, pendingCount: 5, oldestAgeMinutes: 40, thresholdMinutes: 30);

	$html = $twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.jobQueueHealthAlert(health) }}",
		['health' => $health],
	);

	expect($html)->toContain('warning-box');
	expect($html)->toContain('utils/jobqueue');
});

it('renders nothing when the queue is healthy', function (): void {
	$twig   = $this->app->getContainer()->get(TwigEngine::class);
	$health = new JobQueueHealthData(stalled: false, pendingCount: 0, oldestAgeMinutes: 0, thresholdMinutes: 30);

	$html = trim($twig->renderString(
		"{% import 'dashboard-widgets.twig' as d %}{{ d.jobQueueHealthAlert(health) }}",
		['health' => $health],
	));

	expect($html)->toBe('');
});
