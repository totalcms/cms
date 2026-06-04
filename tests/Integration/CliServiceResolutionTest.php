<?php

declare(strict_types=1);

use TotalCMS\Domain\JobQueue\Service\JobRunner;
use TotalCMS\Domain\Search\Job\ReindexJob;
use TotalCMS\TotalCMS;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/*
 * CLI commands construct with only the TotalCMS app and resolve their services
 * lazily from the container at execute() time — via TotalCMS accessors
 * (->jobRunner()) or ->container()->get(X::class). A service anywhere in that
 * graph that injects an unbound dependency (e.g. the bare Psr\Log\LoggerInterface,
 * which has no concrete container binding) can't be built, so the command dies
 * before doing any work. The web app never trips it because it never resolves
 * these services; unit tests miss it because they mock the dependencies. These
 * tests resolve the REAL container — the seam that was missing when `jobs:process`
 * shipped broken (JobRunner -> ReindexJob -> unbound LoggerInterface).
 */

// Specific regression for that exact crash.
it('resolves ReindexJob from the real container', function (): void {
	expect($this->app->getContainer()->get(ReindexJob::class))->toBeInstanceOf(ReindexJob::class);
});

it('resolves JobRunner from the real container', function (): void {
	expect($this->app->getContainer()->get(JobRunner::class))->toBeInstanceOf(JobRunner::class);
});

// Broad guard #1 — every service the TotalCMS facade exposes to CLI commands
// must be buildable. Reflective, so accessors added later are covered for free.
it('resolves every TotalCMS service accessor from the container', function (): void {
	$container  = $this->app->getContainer();
	$reflection = new ReflectionClass(TotalCMS::class);

	$failures = [];
	foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if ($method->isConstructor() || $method->getNumberOfRequiredParameters() > 0) {
			continue;
		}

		$returnType = $method->getReturnType();
		if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
			continue;
		}

		// Only the T3 domain services (skips the DI Container, value objects, etc.).
		$serviceClass = $returnType->getName();
		if (!str_starts_with($serviceClass, 'TotalCMS\\') || !$container->has($serviceClass)) {
			continue;
		}

		try {
			$container->get($serviceClass);
		} catch (\Throwable $e) {
			$failures[] = sprintf('%s() -> %s: %s', $method->getName(), $serviceClass, $e->getMessage());
		}
	}

	expect($failures)->toBe([]);
});

// Broad guard #2 — services CLI commands resolve directly via ->container()->get().
// Derived from `grep -r "container()->get(" src/CLI/Command`. Add to this list when
// a command starts resolving a new service class directly.
it('resolves services CLI commands fetch directly from the container', function (): void {
	$container = $this->app->getContainer();

	$services = [
		\TotalCMS\Domain\Automation\Service\AutomationQueue::class,
		\TotalCMS\Domain\Automation\Service\AutomationLoader::class,
		\TotalCMS\Domain\Automation\Service\AutomationRunner::class,
		\TotalCMS\Domain\Automation\Service\AutomationStateStore::class,
		\TotalCMS\Domain\Automation\Service\ScheduleTicker::class,
		\TotalCMS\Domain\Builder\Service\BuilderConfigService::class,
		\TotalCMS\Domain\Builder\Service\BuilderFrontendInstaller::class,
		\TotalCMS\Domain\Builder\Service\StarterService::class,
		\TotalCMS\Domain\Repair\Service\CollectionFileRepairService::class,
		\TotalCMS\Domain\Extension\Service\ExtensionDiscovery::class,
		\TotalCMS\Domain\Extension\Service\ExtensionManager::class,
		\TotalCMS\Domain\Extension\Repository\ExtensionStateRepository::class,
		\TotalCMS\Domain\Migration\Service\MigrationRunner::class,
		\TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository::class,
		\TotalCMS\Domain\License\Service\EditionFeatureService::class,
		\TotalCMS\Support\Config::class,
	];

	$failures = [];
	foreach ($services as $service) {
		try {
			$container->get($service);
		} catch (\Throwable $e) {
			$failures[] = sprintf('%s: %s', $service, $e->getMessage());
		}
	}

	expect($failures)->toBe([]);
});
