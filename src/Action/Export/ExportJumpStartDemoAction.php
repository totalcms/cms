<?php

declare(strict_types=1);

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ExportJumpStartDemoAction
{
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$demojumpstart = __DIR__ . '/../../../resources/jumpstart/demo.json';
		$jsonData      = file_get_contents($demojumpstart);
		if ($jsonData === false) {
			throw new \RuntimeException(sprintf('Failed to read demo JumpStart file: %s', $demojumpstart));
		}

		// Set response headers for JSON download
		$filename = sprintf('jumpstart-demo-%s.json', date('Ymd-His'));

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

		return $response->withBody(Stream::create($jsonData));
	}
}
