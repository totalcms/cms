<?php

namespace TotalCMS\Action\Assets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
		array $args
	): ResponseInterface {

		if (!isset($args['asset'])) {
			return $response->withStatus(404);
		}

		$assetPath = __DIR__ . '/../../../public/assets/' . $args['asset'];

		if (!file_exists($assetPath)) {
			return $response->withStatus(404);
		}

		$contents = file_get_contents($assetPath);
		$filesize = filesize($assetPath);
		$mimeType = MimeLookup::getMimeType($assetPath);

		if ($filesize === false || $contents === false) {
			return $response->withStatus(500);
		}

		$response->getBody()->write($contents);

		return $response
			->withHeader('Content-Type', $mimeType)
			->withHeader('Content-Length', (string)$filesize);
	}
}
