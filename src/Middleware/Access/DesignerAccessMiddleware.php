<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Template\Data\DesignerMetadata;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Designer Access Middleware.
 *
 * Standalone public middleware for designer API routes.
 * Validates designer token and checks that designer is enabled for the template.
 */
readonly class DesignerAccessMiddleware implements MiddlewareInterface
{
	public function __construct(
		private TemplateRepository $templateRepository,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		$path         = $route instanceof \Slim\Interfaces\RouteInterface ? (string)$route->getArgument('path', '') : '';

		if ($path === '') {
			return $this->errorResponse(404, 'Template path is required');
		}

		// Parse into folder + name
		[$folder, $name] = TemplateRepository::parsePath($path);

		// Check if the template exists
		if (!$this->templateRepository->builderTemplateExists($name, $folder)) {
			return $this->errorResponse(404, 'Template not found');
		}

		// Load designer metadata
		$designerMeta = $this->templateRepository->fetchDesignerMeta($name, $folder);

		if (!$designerMeta instanceof DesignerMetadata || !$designerMeta->designerEnabled) {
			return $this->errorResponse(403, 'Template Designer is not enabled for this template');
		}

		// Validate token from header or query param
		$token = $request->getHeaderLine('X-Designer-Token');
		if ($token === '') {
			$queryParams = $request->getQueryParams();
			$token       = (string)($queryParams['token'] ?? '');
		}

		if ($token === '' || !hash_equals($designerMeta->designerToken, $token)) {
			return $this->errorResponse(401, 'Invalid or missing designer token');
		}

		// Attach parsed template info to request for downstream actions
		$request = $request->withAttribute('designerFolder', $folder);
		$request = $request->withAttribute('designerTemplate', $name);

		return $handler->handle($request);
	}

	private function errorResponse(int $status, string $message): ResponseInterface
	{
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus($status),
			['error' => ['message' => $message]]
		);
	}
}
