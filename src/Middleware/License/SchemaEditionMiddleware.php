<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to block access to schemas based on edition.
 *
 * - Blog, blog-legacy, depot schemas require Standard+
 * - Custom schemas require Pro
 */
readonly class SchemaEditionMiddleware extends BaseEditionMiddleware
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
		// Get schema from route argument
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();

		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			return $handler->handle($request);
		}

		$schema = $route->getArgument('schema') ?? $route->getArgument('id');

		if ($schema === null) {
			// No schema ID — this is a POST to create a new schema (requires Pro)
			if ($request->getMethod() === 'POST' && !$this->editionFeatures->can(EditionFeature::CUSTOM_SCHEMAS)) {
				$feature = EditionFeature::CUSTOM_SCHEMAS;

				$message = sprintf(
					'The "%s" feature requires the %s edition or higher.',
					$feature->label(),
					ucfirst($feature->requiredEdition()->value)
				);

				if ($this->config->env === 'dev') {
					$message .= sprintf(' Current edition: %s.', ucfirst($this->editionFeatures->getEdition()->value));
				}

				return $this->forbiddenResponse($request, $message);
			}

			return $handler->handle($request);
		}

		// Check if schema is accessible with current edition
		if (!$this->collectionEditionService->isSchemaAccessible($schema)) {
			$currentEdition  = $this->editionFeatures->getEdition();
			$requiredEdition = $this->collectionEditionService->getRequiredEditionForSchema($schema);

			$message = sprintf(
				'This schema requires the %s edition. Current edition: %s.',
				$requiredEdition instanceof Edition ? ucfirst($requiredEdition->value) : 'Pro',
				ucfirst($currentEdition->value)
			);

			return $this->forbiddenResponse($request, $message);
		}

		return $handler->handle($request);
	}
}
