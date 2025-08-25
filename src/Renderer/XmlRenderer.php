<?php

namespace TotalCMS\Renderer;

use Psr\Http\Message\ResponseInterface;

class XmlRenderer
{
	public function xml(
		ResponseInterface $response,
		string $xml = '',
	): ResponseInterface {
		$response = $response->withHeader('Content-Type', 'application/xml');
		$response->getBody()->write($xml);

		return $response;
	}
}
