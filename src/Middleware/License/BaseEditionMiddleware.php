<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
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
			$requiredEdition = $feature->requiredEdition();
			$currentEdition  = $this->editionFeatures->getEdition();

			$message = sprintf(
				'The "%s" feature requires the %s edition or higher.',
				$feature->label(),
				ucfirst($requiredEdition->value)
			);

			if ($this->config->env === 'dev') {
				$message .= sprintf(' Current edition: %s.', ucfirst($currentEdition->value));
			}

			return $this->forbiddenResponse($request, $message);
		}

		return $handler->handle($request);
	}

	/**
	 * Return a 403 Forbidden response (JSON for API, HTML for admin UI).
	 */
	protected function forbiddenResponse(ServerRequestInterface $request, string $message): ResponseInterface
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
