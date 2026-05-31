<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Action for displaying the extension management page.
 */
readonly class AdminExtensionsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ExtensionManager $manager,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		return $this->twigRenderer->template($response, 'admin/extensions.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensions' => $this->manager->listExtensions(),
			'budgets'    => [
				'perExtension' => (int)($this->config->extensions['budgetMsPerExtension'] ?? 200),
				'perStack'     => (int)($this->config->extensions['budgetMsPerStack'] ?? 500),
			],
		]);
	}
}
