<?php

declare(strict_types=1);

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/*
 * Core event listeners are registered in config/container.php via $lazy(X::class,
 * 'method') closures — the listener class is resolved from the container on first
 * dispatch, not at registration time. A listener anywhere in that set that injects
 * an unbound dependency (e.g. the bare Psr\Log\LoggerInterface, which has no
 * concrete container binding) can't be built, so EVERY dispatch of its events
 * logs a DI error and silently drops the listener's work. CliServiceResolutionTest
 * misses these because the web/CLI never resolves them directly — only the
 * dispatcher does, lazily, inside its catch-all. That's how ContentChangeListener
 * shipped broken in 3.5.0-rc.5: every object.created/updated/deleted dispatch
 * logged "Psr\Log\LoggerInterface cannot be resolved" instead of pushing the
 * change to the active search provider.
 *
 * This sweep parses container.php for every $lazy(...) listener class and
 * resolves each through the REAL container, so new listeners are covered for
 * free the moment they're registered.
 */

it('resolves every lazily-registered core event listener from the real container', function (): void {
	$source = (string)file_get_contents(dirname(__DIR__, 2) . '/config/container.php');

	// Map short class names from the file's use-imports to their FQNs.
	preg_match_all('/^use ([\w\\\\]+);/m', $source, $useMatches);
	$imports = [];
	foreach ($useMatches[1] as $fqn) {
		$imports[substr($fqn, (int)strrpos($fqn, '\\') + 1)] = $fqn;
	}

	// Every class handed to the $lazy() helper inside the EventDispatcher boot.
	preg_match_all('/\$lazy\(\s*([\w\\\\]+)::class/', $source, $lazyMatches);
	$listenerClasses = array_unique(array_map(
		static fn (string $name): string => $imports[$name] ?? ltrim($name, '\\'),
		$lazyMatches[1],
	));

	expect($listenerClasses)->not->toBe([]);

	$container = $this->app->getContainer();
	$failures  = [];
	foreach ($listenerClasses as $class) {
		try {
			$container->get($class);
		} catch (Throwable $e) {
			$failures[] = sprintf('%s: %s', $class, $e->getMessage());
		}
	}

	expect($failures)->toBe([]);
});

// AutomationEventSubscriber is registered with a hand-rolled closure (not $lazy)
// so the parse above doesn't see it — pin it explicitly.
it('resolves AutomationEventSubscriber from the real container', function (): void {
	$subscriber = $this->app->getContainer()->get(\TotalCMS\Domain\Automation\Service\AutomationEventSubscriber::class);
	expect($subscriber)->toBeInstanceOf(\TotalCMS\Domain\Automation\Service\AutomationEventSubscriber::class);
});
