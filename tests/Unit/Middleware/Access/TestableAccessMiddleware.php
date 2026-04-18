<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\BaseAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Concrete BaseAccessMiddleware subclass used by BaseAccessMiddlewareTest.
 *
 * Lives in its own file so the PSR-4 class-name = filename rule (psr_autoloading
 * in php-cs-fixer) is satisfied. BaseAccessMiddleware is readonly, so this
 * subclass must be readonly too; checkPermission logic is injected via a
 * constructor-time callable.
 */
readonly class TestableAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'widget';

	public function __construct(
		UserValidationService $userValidation,
		AccessControlService $accessControl,
		SessionInterface $session,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		private \Closure $checkPermissionCallback,
	) {
		parent::__construct($userValidation, $accessControl, $session, $jsonRenderer, $twigRenderer, $responseFactory, $config, $operationDetector, $loggerFactory);
	}

	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		return ($this->checkPermissionCallback)($userId, $operation, $request);
	}
}
