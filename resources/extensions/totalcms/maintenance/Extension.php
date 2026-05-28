<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Maintenance;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

require_once __DIR__ . '/MaintenanceMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$defaultMessage    = (string)$context->setting('message', MaintenanceMiddleware::DEFAULT_MESSAGE);
		$defaultRetryAfter = (int)$context->setting('retryAfter', MaintenanceMiddleware::DEFAULT_RETRY_AFTER);

		$context->addContainerDefinition(
			MaintenanceMiddleware::class,
			fn () => new MaintenanceMiddleware($defaultMessage, $defaultRetryAfter),
		);

		$context->addPageMiddleware('maintenance', MaintenanceMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
