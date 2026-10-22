<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
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
		ActorResolver $actorResolver,
		ActorContext $actors,
		JsonRenderer $jsonRenderer,
		TwigRenderer $twigRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
		OperationDetector $operationDetector,
		LoggerFactory $loggerFactory,
		AccessDenials $denials,
		private \Closure $checkPermissionCallback,
	) {
		parent::__construct($actorResolver, $actors, $jsonRenderer, $twigRenderer, $responseFactory, $config, $operationDetector, $loggerFactory, $denials);
	}

	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return ($this->checkPermissionCallback)($actor, $operation, $request);
	}
}
