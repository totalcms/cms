<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Scheduled;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

require_once __DIR__ . '/ScheduledMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addContainerDefinition(
			ScheduledMiddleware::class,
			fn () => new ScheduledMiddleware(),
		);

		$context->addPageMiddleware('scheduled', ScheduledMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
