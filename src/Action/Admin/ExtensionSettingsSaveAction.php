<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Save extension settings from the form POST.
 */
readonly class ExtensionSettingsSaveAction
{
	public function __construct(
		private ExtensionDiscovery $discovery,
		private ExtensionSettingsManager $settingsManager,
		private JsonRenderer $renderer,
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
		if (!isset($manifests[$extensionId])) {
			return $this->renderer->json($response, ['error' => 'Extension not found'])->withStatus(404);
		}

		$body = (array) $request->getParsedBody();

		// Remove framework fields
		unset($body['_csrf_token'], $body['_method']);

		$this->settingsManager->saveSettings($extensionId, $body);

		return $this->renderer->json($response, [
			'status'  => 'success',
			'message' => 'Settings saved',
		]);
	}
}
