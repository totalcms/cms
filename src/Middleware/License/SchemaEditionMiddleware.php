<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Middleware to block access to schemas based on edition.
 *
 * - Blog, blog-legacy, depot schemas require Standard+
 * - Custom schemas require Pro
 */
readonly class SchemaEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		private CollectionEditionService $collectionEditionService,
		private EditionFeatureService $editionFeatures,
	) {
	}

	/**
	 * Process the request and check schema edition access.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Get schema from route argument
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return $handler->handle($request);
		}

		$schema = $route->getArgument('schema') ?? $route->getArgument('id');

		if ($schema === null) {
			// No schema specified (e.g., listing schemas) - allow through
			return $handler->handle($request);
		}

		// Check if schema is accessible with current edition
		if (!$this->collectionEditionService->isSchemaAccessible($schema)) {
			$currentEdition = $this->editionFeatures->getEdition();

			throw new HttpForbiddenException(
				$request,
				sprintf(
					'This schema requires a higher edition. Current edition: %s. Please upgrade to access this schema.',
					ucfirst($currentEdition->value)
				)
			);
		}

		return $handler->handle($request);
	}
}
