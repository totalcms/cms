<?php

namespace TotalCMS\Action\Assets;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Utils\MimeLookup;

// This Action is used to serve static assets from the public/assets directory
// This should really only ever be used with using the PHP built-in server
final class StaticPublicAssetsAction
{
	/**
	 * @param array<string,mixed> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		if (!isset($args['asset'])) {
			throw new \UnexpectedValueException('Missing asset argument');
		}

		// Prevent path traversal attacks by validating the asset path
		$asset = $args['asset'];

		// Remove any directory traversal sequences
		$asset = str_replace(['../', '..\\', '\\'], '', $asset);

		// Ensure the asset path contains only safe characters and forward slashes
		if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $asset)) {
			throw new HttpNotFoundException($request, 'Invalid asset path');
		}

		$assetPath = __DIR__ . '/../../../public/assets/' . $asset;

		// Ensure the resolved path is within the assets directory
		$assetsDir    = realpath(__DIR__ . '/../../../public/assets');
		$resolvedPath = realpath($assetPath);

		if ($resolvedPath === false || $assetsDir === false || strpos($resolvedPath, $assetsDir) !== 0) {
			throw new HttpNotFoundException($request, 'Asset not found');
		}

		if (!file_exists($assetPath)) {
			throw new HttpNotFoundException($request, 'Asset not found');
		}

		$filesize = filesize($assetPath);
		$mimeType = MimeLookup::getMimeType($assetPath);
		$stream   = fopen($assetPath, 'r');

		if ($filesize === false || $stream === false) {
			throw new \RuntimeException('Failed to read asset file');
		}

		return $response
			->withHeader('Content-Type', $mimeType)
			->withHeader('Content-Length', (string)$filesize)
			->withBody(Stream::create($stream));
	}
}
