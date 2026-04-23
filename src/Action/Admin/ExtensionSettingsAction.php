<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Display the settings form for an extension.
 */
readonly class ExtensionSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ExtensionDiscovery $discovery,
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
		$extensionId = $args['extension'] ?? '';

		$manifests = $this->discovery->discover();
		$manifest  = $manifests[$extensionId] ?? null;

		if ($manifest === null) {
			return $response->withStatus(404);
		}

		return $this->twigRenderer->template($response, 'admin/extension-settings.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensionId'   => $extensionId,
			'extensionName' => $manifest->name,
		]);
	}
}
