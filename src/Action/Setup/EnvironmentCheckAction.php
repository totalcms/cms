<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Setup wizard environment check: PHP version, extensions, requirements.
 */
readonly class EnvironmentCheckAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ServerChecker $serverChecker,
		private SetupStateManager $setupState,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$required = $this->serverChecker->checkRequiredSoftware();
		$optional = $this->serverChecker->checkOptionalSoftware();
		$details  = $this->serverChecker->getOptionalSoftwareDetails();

		$allRequiredPassed = !in_array(false, $required, true);

		if ($allRequiredPassed) {
			$this->setupState->completeStep('environment');
		}

		return $this->twigRenderer->template($response, 'setup/environment.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'required'          => $required,
			'optional'          => $optional,
			'optionalDetails'   => $details,
			'allRequiredPassed' => $allRequiredPassed,
			'phpVersion'        => PHP_VERSION,
		]);
	}
}
