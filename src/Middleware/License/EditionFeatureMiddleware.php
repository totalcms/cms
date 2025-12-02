<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Middleware to gate routes by required edition feature.
 *
 * Usage:
 * $route->add(new EditionFeatureMiddleware($editionFeatures, EditionFeature::CUSTOM_SCHEMAS));
 */
readonly class EditionFeatureMiddleware implements MiddlewareInterface
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private EditionFeature $requiredFeature,
	) {
	}

	/**
	 * Process the request and check edition feature access.
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (!$this->editionFeatures->can($this->requiredFeature)) {
			$requiredEdition = $this->requiredFeature->requiredEdition();
			$currentEdition  = $this->editionFeatures->getEdition();

			throw new HttpForbiddenException(
				$request,
				sprintf(
					'The "%s" feature requires the %s edition or higher. Current edition: %s. Please upgrade your license to access this feature.',
					$this->requiredFeature->label(),
					ucfirst($requiredEdition->value),
					ucfirst($currentEdition->value)
				)
			);
		}

		return $handler->handle($request);
	}
}
