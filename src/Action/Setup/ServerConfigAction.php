<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Setup\Service\ServerConfigAdvisor;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Setup wizard step: server configuration hints (rewrite rules + cron).
 */
readonly class ServerConfigAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ServerConfigAdvisor $advisor,
		private SetupStateManager $setupState,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$this->setupState->completeStep('server-config');

		return $this->twigRenderer->template($response, 'setup/server-config.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'detected'         => $this->advisor->detectServer(),
			'serverHeader'     => $this->advisor->serverSoftware(),
			'publicPrefix'     => $this->advisor->publicUrlPrefix(),
			'installPrefix'    => $this->advisor->installUrlPrefix(),
			'apacheConfig'     => $this->advisor->apacheRewrite(),
			'hasApacheConfig'  => $this->advisor->hasApacheHtaccess(),
			'nginxConfig'      => $this->advisor->nginxConfig(),
			'cronCommand'      => $this->advisor->cronCommand(),
		]);
	}
}
