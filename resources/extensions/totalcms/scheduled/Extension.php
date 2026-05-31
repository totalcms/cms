<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Scheduled;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Support\Config;

require_once __DIR__ . '/ScheduledMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addContainerDefinition(
			ScheduledMiddleware::class,
			static fn (ContainerInterface $container): ScheduledMiddleware => new ScheduledMiddleware(
				// Logged-in admins/operators preview the page instead of the time
				// gate — `userLoggedIn($operatorCollection)` excludes front-end
				// members (public registration) while still passing super-admins.
				// Resolved lazily and read at request time so it reflects the live
				// session.
				static fn (): bool => $container->get(AccessManager::class)->userLoggedIn(
					(string)($container->get(Config::class)->auth['collection'] ?? ''),
				),
			),
		);

		$context->addPageMiddleware('scheduled', ScheduledMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
