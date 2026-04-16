<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action for displaying the extension management page.
 */
readonly class AdminExtensionsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ExtensionDiscovery $discovery,
		private ExtensionStateRepository $stateRepository,
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
		$manifests = $this->discovery->discover();
		$states    = $this->stateRepository->loadAll();

		$extensions = [];
		foreach ($manifests as $id => $manifest) {
			$state        = $states[$id] ?? null;
			$extensions[] = [
				'id'          => $id,
				'name'        => $manifest->name,
				'description' => $manifest->description,
				'version'     => $manifest->version,
				'author'      => $manifest->author,
				'license'     => $manifest->license,
				'permissions' => $manifest->permissions,
				'enabled'     => $state !== null && $state->enabled,
				'error'       => $state?->error,
			];
		}

		return $this->twigRenderer->template($response, 'admin/extensions.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensions' => $extensions,
		]);
	}
}
