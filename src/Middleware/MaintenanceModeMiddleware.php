<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Update\Service\MaintenanceMode;

/**
 * Serves a static maintenance page when an update is in progress.
 * Admin routes pass through so the update can complete.
 */
readonly class MaintenanceModeMiddleware implements MiddlewareInterface
{
	public function __construct(
		private MaintenanceMode $maintenanceMode,
	) {
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if (!$this->maintenanceMode->isEnabled()) {
			return $handler->handle($request);
		}

		// Allow admin and setup routes through during maintenance
		$path = $request->getUri()->getPath();
		if (str_contains($path, '/admin') || str_contains($path, '/setup')) {
			return $handler->handle($request);
		}

		$response = new Response();
		$response->getBody()->write($this->maintenancePage());

		return $response
			->withStatus(503)
			->withHeader('Content-Type', 'text/html')
			->withHeader('Retry-After', '60');
	}

	private function maintenancePage(): string
	{
		return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Updating...</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f5f5f5; color: #333; }
.container { text-align: center; padding: 2rem; }
h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
p { color: #666; }
</style>
</head>
<body>
<div class="container">
<h1>Updating Total CMS</h1>
<p>Please wait while the update is being applied. This page will refresh automatically.</p>
</div>
<script>setTimeout(() => location.reload(), 10000);</script>
</body>
</html>
HTML;
	}
}
