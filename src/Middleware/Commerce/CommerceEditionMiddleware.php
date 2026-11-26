<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Middleware\License\BaseEditionMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Gates every storefront and admin commerce route.
 *
 * An edition feature alone cannot say it: commerce needs the Pro edition and
 * the paid Commerce add-on, so this asks {@see CommerceGate} for both.
 *
 * Two commerce routes are deliberately NOT behind it: the payment webhook and
 * the download of a purchased file. Money already taken and goods already
 * bought must keep working if the add-on lapses.
 */
readonly class CommerceEditionMiddleware extends BaseEditionMiddleware
{
	public const MESSAGE = CommerceGateStatus::MESSAGE;

	public function __construct(
		private CommerceGate $gate,
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
		$status = $this->gate->status();
		if ($status === CommerceGateStatus::Allowed) {
			return $handler->handle($request);
		}

		return $this->forbiddenResponse($request, $status->refusal());
	}
}
