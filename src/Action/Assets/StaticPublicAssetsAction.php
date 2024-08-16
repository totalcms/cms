<?php

namespace TotalCMS\Action\Assets;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Utils\MimeLookup;
use Slim\Exception\HttpNotFoundException;

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
			throw new \UnexpectedValueException("Missing asset argument");
		}

		$assetPath = __DIR__ . '/../../../public/assets/' . $args['asset'];

		if (!file_exists($assetPath)) {
			throw new HttpNotFoundException($request, 'Asset not found');
		}

		$contents = file_get_contents($assetPath);
		$filesize = filesize($assetPath);
		$mimeType = MimeLookup::getMimeType($assetPath);

		if ($filesize === false || $contents === false) {
			throw new \RuntimeException("Failed to read asset file");
		}

		$response->getBody()->write($contents);

		return $response
			->withHeader('Content-Type', $mimeType)
			->withHeader('Content-Length', (string)$filesize);
	}
}
