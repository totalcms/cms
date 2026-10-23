<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\Install\UploadLimit;
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
		$extensions = $this->manager->listExtensions();

		return $this->twigRenderer->template($response, 'admin/extensions.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensions'  => $extensions,
			'installed'   => $this->installedExtension($request, $extensions),
			'uploadLimit' => UploadLimit::describe(),
			'budgets'     => [
				'perExtension' => (int)($this->config->extensions['budgetMsPerExtension'] ?? 200),
				'perStack'     => (int)($this->config->extensions['budgetMsPerStack'] ?? 500),
			],
		]);
	}

	/**
	 * The extension a fresh install just landed (`?installed=vendor/name`),
	 * for the status line and the highlighted card. Only an id of the right
	 * shape that names an extension on this page counts; anything else is
	 * ignored.
	 *
	 * @param list<array<string,mixed>> $extensions
	 *
	 * @return array<string,mixed>|null
	 */
	private function installedExtension(ServerRequestInterface $request, array $extensions): ?array
	{
		$installed = $request->getQueryParams()['installed'] ?? null;
		if (!is_string($installed) || preg_match('/^[a-z0-9-]+\/[a-z0-9-]+\z/', $installed) !== 1) {
			return null;
		}

		foreach ($extensions as $extension) {
			if (($extension['id'] ?? null) === $installed) {
				return $extension;
			}
		}

		return null;
	}
}
