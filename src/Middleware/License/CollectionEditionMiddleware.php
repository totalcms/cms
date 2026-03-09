<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to block access to collections using custom schemas without Pro edition.
 *
 * Applied to collection routes to enforce edition-based access control.
 * Collections using custom schemas require Pro edition.
 */
readonly class CollectionEditionMiddleware extends BaseEditionMiddleware
{
	public function __construct(
		private CollectionEditionService $collectionEditionService,
		EditionFeatureService $editionFeatures,
		TwigRenderer $twigRenderer,
		JsonRenderer $jsonRenderer,
		ResponseFactoryInterface $responseFactory,
		Config $config,
	) {
		parent::__construct($editionFeatures, $twigRenderer, $jsonRenderer, $responseFactory, $config);
	}

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
			$currentEdition  = $this->editionFeatures->getEdition();
			$requiredEdition = $this->collectionEditionService->getRequiredEditionForCollection($collection);

			$message = sprintf(
				'This collection requires the %s edition. Current edition: %s.',
				$requiredEdition instanceof Edition ? ucfirst($requiredEdition->value) : 'Pro',
				ucfirst($currentEdition->value)
			);

			return $this->forbiddenResponse($request, $message);
		}

		return $handler->handle($request);
	}
}
