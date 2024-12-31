<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Admin\SettingsSaver;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private SettingsSaver $settingsSaver,
	) {
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$savedSettings = [];
		$defaults = require __DIR__ . '/../../../config/settings.php';

		if ($request->getMethod() === 'POST') {
			$savedSettings = (array)$request->getParsedBody();
			$savedSettings = array_filter($savedSettings, fn($value) => $value !== '');
			$this->settingsSaver->save($savedSettings);
		}

		if (empty($savedSettings)) {
			$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
			if (file_exists($configFile)) {
				$savedSettings = require $configFile;
			}
		}

		return $this->twigRenderer->template($response, 'admin/settings.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'settings',
			],
			'timezones' => timezone_identifiers_list(),
			'settings'  => $savedSettings,
			'defaults'  => $defaults,
		]);
	}
}
