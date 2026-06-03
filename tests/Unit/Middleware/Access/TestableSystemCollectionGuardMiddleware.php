<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Middleware\Access\SystemCollectionGuardMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

/**
 * Test double for SystemCollectionGuardMiddleware that overrides route-argument
 * resolution so the gate logic can be exercised directly.
 *
 * Lives in its own file (classname === filename) on purpose: the `psr_autoloading`
 * CS-Fixer rule renames a lone class in a `*Test.php` file to match the filename,
 * which would both break the reference and give it a test-runner-significant
 * `*Test` suffix. Keeping it here keeps the `Testable` name stable.
 */
final readonly class TestableSystemCollectionGuardMiddleware extends SystemCollectionGuardMiddleware
{
	public function __construct(
		SessionInterface $session,
		UserValidationService $userValidation,
		JsonRenderer $jsonRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		private string $collection,
	) {
		parent::__construct($session, $userValidation, $jsonRenderer, $responseFactory, $config);
	}

	protected function resolveCollection(ServerRequestInterface $request): string
	{
		return $this->collection;
	}
}
