<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Infrastructure\Filesystem\MimeLookup;

/**
 * Serves static assets from extension assets/ directories.
 *
 * Route: GET /ext/{vendor}/{name}/assets/{file}
 * No authentication required.
 */
readonly class ExtensionAssetAction
{
	public function __construct(
		private ExtensionManager $extensionManager,
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
		$extensionId = ($args['vendor'] ?? '') . '/' . ($args['name'] ?? '');
		$file        = $args['file'] ?? '';

		// Block path traversal
		if (str_contains($file, '..')) {
			return $response->withStatus(404);
		}

		$extPath = $this->extensionManager->getExtensionPath($extensionId);
		if ($extPath === null) {
			return $response->withStatus(404);
		}

		$filePath = $extPath . '/assets/' . $file;
		if (!is_file($filePath)) {
			return $response->withStatus(404);
		}

		$response->getBody()->write((string)file_get_contents($filePath));

		return $response
			->withHeader('Content-Type', MimeLookup::getMimeType($filePath))
			->withHeader('Cache-Control', 'public, max-age=86400');
	}
}
