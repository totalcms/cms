<?php

namespace TotalCMS\Middleware\License;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Middleware to gate mailer routes by edition.
 * Requires Standard or higher edition for mailer actions feature.
 */
readonly class MailerEditionMiddleware implements MiddlewareInterface
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private TwigRenderer $twigRenderer,
		private ResponseFactoryInterface $responseFactory,
		private Config $config,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$feature = EditionFeature::MAILER_ACTIONS;

		if (!$this->editionFeatures->can($feature)) {
			$requiredEdition = $feature->requiredEdition();
			$currentEdition  = $this->editionFeatures->getEdition();

			$message = sprintf(
				'The "%s" feature requires the %s edition or higher.',
				$feature->label(),
				ucfirst($requiredEdition->value)
			);

			$details = $this->config->env === 'dev'
				? sprintf("Current edition: %s\nRequired: %s", ucfirst($currentEdition->value), ucfirst($requiredEdition->value))
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

		return $handler->handle($request);
	}
}
