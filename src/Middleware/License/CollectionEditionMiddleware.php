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
 * Middleware to block access to collections using custom schemas without Pro edition.
 *
 * Applied to collection routes to enforce edition-based access control.
 * Collections using custom schemas require Pro edition.
 */
readonly class CollectionEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		private CollectionEditionService $collectionEditionService,
		private EditionFeatureService $editionFeatures,
	) {
	}

	/**
	 * Process the request and check collection edition access.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		// Get collection from route argument
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return $handler->handle($request);
		}

		$collection = $route->getArgument('collection');

		if ($collection === null) {
			// No collection specified (e.g., listing collections) - allow through
			return $handler->handle($request);
		}

		// Check if collection is accessible with current edition
		if (!$this->collectionEditionService->isAccessible($collection)) {
			$currentEdition = $this->editionFeatures->getEdition();

			throw new HttpForbiddenException(
				$request,
				sprintf(
					'This collection uses a custom schema which requires Pro edition. Current edition: %s. Please upgrade to access this collection.',
					ucfirst($currentEdition->value)
				)
			);
		}

		return $handler->handle($request);
	}
}
