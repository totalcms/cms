<?php

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Display the data path setup form.
 * This is the first step in Total CMS setup - choosing where to store data.
 */
readonly class DataPathSetupAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// Get docroot from $_SERVER['DOCUMENT_ROOT']
		$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);

		// Calculate default paths
		$defaultPath  = dirname($docroot) . '/tcms-data';
		$docrootPath  = $docroot . '/tcms-data';

		$templateData = [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'defaultPath'  => $defaultPath,
			'docrootPath'  => $docrootPath,
			'customPath'   => $defaultPath, // Pre-fill custom with default
		];

		return $this->twigRenderer->template($response, 'setup/data-path.twig', $templateData);
	}
}
