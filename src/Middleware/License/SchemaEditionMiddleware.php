<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
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
readonly class SchemaEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		private CollectionEditionService $collectionEditionService,
		private EditionFeatureService $editionFeatures,
		private TwigRenderer $twigRenderer,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
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
			$currentEdition  = $this->editionFeatures->getEdition();
			$requiredEdition = $this->collectionEditionService->getRequiredEditionForSchema($schema);

			$message = sprintf(
				'This schema requires the %s edition. Current edition: %s.',
				$requiredEdition ? ucfirst($requiredEdition->value) : 'Pro',
				ucfirst($currentEdition->value)
			);

			return $this->forbiddenResponse($request, $message);
		}

		return $handler->handle($request);
	}

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	private function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
	{
		$path = $request->getUri()->getPath();

		// Admin UI requests get HTML response
		if (str_starts_with($path, '/admin/')) {
			$details = $this->config->env === 'dev'
				? sprintf("Path: %s\nEdition: %s", $path, ucfirst($this->editionFeatures->getEdition()->value))
				: null;

			return $this->twigRenderer->template(
				$this->responseFactory->createResponse()->withStatus(403),
				'access-denied.twig',
				[
					'message'  => $message,
					'details'  => $details,
					'referrer' => $request->getHeaderLine('Referer') ?: null,
				]
			);
		}

		// API requests get JSON response
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(403),
			['error' => ['message' => $message]]
		);
	}
}
