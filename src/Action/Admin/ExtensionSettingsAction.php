<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Display the settings form for an extension.
 */
readonly class ExtensionSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ExtensionManager $manager,
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
		$extension   = $this->manager->getExtension($extensionId);

		if ($extension === null) {
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
			'extensionName' => $extension['name'],
			'extension'     => $extension,
		]);
	}
}
