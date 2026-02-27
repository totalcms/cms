<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Renderer\JsonRenderer;

readonly class PasskeyLoginOptionsAction
{
	public function __construct(
		private PasskeyService $passkeyService,
		private JsonRenderer $jsonRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$options = $this->passkeyService->generateAuthenticationOptions();

		return $this->jsonRenderer->json($response, $options);
	}
}
