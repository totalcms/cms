<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
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
		$defaults      = require __DIR__ . '/../../../config/settings.php';

		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		if (file_exists($configFile)) {
			$savedSettings = require $configFile;
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
