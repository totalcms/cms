<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;

readonly class LogDownloadAction
{
	public function __construct(
		private LogAnalyzer $logAnalyzer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$params = $request->getQueryParams();
		$logfile = $params['logfile'] ?? '';

		$logfiles = $this->logAnalyzer->logfiles();

		if ($logfile === '' || !array_key_exists($logfile, $logfiles)) {
			return $response->withStatus(404);
		}

		$filePath = $logfiles[$logfile];
		$handle = fopen($filePath, 'r');

		if ($handle === false) {
			return $response->withStatus(500);
		}

		$stream = new Stream($handle);

		return $response
			->withHeader('Content-Type', 'text/plain')
			->withHeader('Content-Disposition', 'attachment; filename="' . $logfile . '"')
			->withBody($stream);
	}
}
