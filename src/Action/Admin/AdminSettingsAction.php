<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
final class AdminSettingsAction
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

		if ($request->getMethod() === 'POST') {
			var_dump($request->getParsedBody());
		}

		$defaults = require __DIR__ . '/../../../config/settings.php';

		$settings = [];
		$configFile = $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
		if (file_exists($configFile)) {
			$settings = require $configFile;
		}


		return $this->twigRenderer->template($response, 'admin/settings.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'settings',
			],
			'timezones' => timezone_identifiers_list(),
			'settings'  => $settings,
			'defaults'  => $defaults,
		]);
	}
}
