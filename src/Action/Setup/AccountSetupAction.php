<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Step 3 of the setup wizard: create the first admin account.
 */
readonly class AccountSetupAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'setup/account.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
		]);
	}
}
