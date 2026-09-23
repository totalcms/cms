<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\ForbiddenRenderer;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Abstract base class for edition-gating middleware.
 *
 * Simple subclasses only need to implement getFeature() to specify which
 * EditionFeature they gate. Specialized subclasses (e.g., collection/schema)
 * can override process() and reuse forbiddenResponse().
 */
abstract readonly class BaseEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		protected EditionFeatureService $editionFeatures,
		protected TwigRenderer $twigRenderer,
		protected JsonRenderer $jsonRenderer,
		protected ResponseFactoryInterface $responseFactory,
		protected Config $config,
	) {
	}

	/**
	 * Return the edition feature this middleware gates.
	 *
	 * Override in simple subclasses that check a single feature.
	 * Specialized subclasses that override process() don't need this.
	 */
	protected function getFeature(): ?EditionFeature
	{
		return null;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$feature = $this->getFeature();

		if ($feature instanceof EditionFeature && !$this->editionFeatures->can($feature)) {
			return $this->forbiddenResponse(
				$request,
				$feature->deniedMessage($this->config->env === 'dev' ? $this->editionFeatures->getEdition() : null),
			);
		}

		return $handler->handle($request);
	}

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	protected function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
	{
		$details = $this->config->env === 'dev'
			? sprintf("Path: %s\nEdition: %s", $request->getUri()->getPath(), ucfirst($this->editionFeatures->getEdition()->value))
			: null;

		return (new ForbiddenRenderer($this->twigRenderer, $this->jsonRenderer, $this->responseFactory))
			->forbidden($request, $message, $details);
	}
}
