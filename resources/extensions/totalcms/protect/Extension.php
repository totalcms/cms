<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Protect;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

require_once __DIR__ . '/ProtectMiddleware.php';

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$defaultPasscode    = (string)$context->setting('passcode', '');
		$defaultPromptTitle = (string)$context->setting('promptTitle', 'Enter passcode to view');

		$context->addContainerDefinition(
			ProtectMiddleware::class,
			fn () => new ProtectMiddleware($defaultPasscode, $defaultPromptTitle),
		);

		$context->addPageMiddleware('protect', ProtectMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
