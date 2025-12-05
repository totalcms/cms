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
 * Middleware to gate access groups routes by edition.
 * Requires Standard or higher edition for access groups feature.
 */
readonly class AccessGroupsEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private TwigRenderer $twigRenderer,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$feature = EditionFeature::ACCESS_GROUPS;

		if (!$this->editionFeatures->can($feature)) {
			$requiredEdition = $feature->requiredEdition();
			$currentEdition  = $this->editionFeatures->getEdition();

			$message = sprintf(
				'The "%s" feature requires the %s edition. Current edition: %s.',
				$feature->label(),
				ucfirst($requiredEdition->value),
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
