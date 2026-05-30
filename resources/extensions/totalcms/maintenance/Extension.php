<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Maintenance;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

require_once __DIR__ . '/MaintenanceMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$defaultMessage           = (string)$context->setting('message', MaintenanceMiddleware::DEFAULT_MESSAGE);
		$defaultRetryAfterMinutes = (int)$context->setting('retryAfterMinutes', MaintenanceMiddleware::DEFAULT_RETRY_AFTER_MINUTES);
		$defaultHeading           = (string)$context->setting('heading', MaintenanceMiddleware::DEFAULT_HEADING);
		$template                 = (string)$context->setting('template', '');

		$context->addContainerDefinition(
			MaintenanceMiddleware::class,
			static fn (ContainerInterface $container): MaintenanceMiddleware => new MaintenanceMiddleware(
				$defaultMessage,
				$defaultRetryAfterMinutes,
				$defaultHeading,
				$template,
				// The setting stores a bare page-template name (from the `pages`
				// propertyOptions source, e.g. `maintenance`); resolve it to the
				// builder path TwigEngine expects. Resolved lazily so the engine is
				// only built when a maintenance page is actually served.
				static fn (string $name, array $vars): string => $container->get(TwigEngine::class)->render('pages/' . $name . '.twig', $vars),
				// Logged-in admins/operators preview the page instead of the gate —
				// `userLoggedIn($operatorCollection)` excludes front-end members
				// (public registration) while still passing super-admins. Resolved
				// lazily and read at request time so it reflects the live session.
				static fn (): bool => $container->get(AccessManager::class)->userLoggedIn(
					(string)($container->get(Config::class)->auth['collection'] ?? ''),
				),
			),
		);

		$context->addPageMiddleware('maintenance', MaintenanceMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
