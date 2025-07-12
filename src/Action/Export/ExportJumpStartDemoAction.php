<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ExportJumpStartDemoAction
{
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$demojumpstart = __DIR__ . '/../../../resources/jumpstart/demo.json';
		$jsonData      = (string)file_get_contents($demojumpstart);

		// Set response headers for JSON download
		$filename = sprintf('jumpstart-demo-%s.json', date('Ymd-His'));

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

		return $response->withBody(Stream::create($jsonData));
	}
}
