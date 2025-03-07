<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Admin\SettingsSaver;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Action.
 */
final class AdminSettingsSaveAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SettingsSaver $settingsSaver,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$savedSettings = (array)$request->getParsedBody();
		$savedSettings = $this->settingsSaver->save($savedSettings);

		// if (empty($savedSettings)) {
		// 	$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		// 	if (file_exists($configFile)) {
		// 		$savedSettings = require $configFile;
		// 	}
		// }

		return $this->renderer->json($response, ['settings' => $savedSettings]);
	}
}
