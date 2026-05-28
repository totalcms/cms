<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Maintenance;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

/**
 * Per-page 503 maintenance mode — takes individual pages offline with a
 * custom message while the rest of the site stays up. Different from T3's
 * site-wide MaintenanceModeMiddleware (which is all-or-nothing).
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "maintenance": {
 *         "message":    "This section is being updated. Back at 5pm EST.",
 *         "retryAfter": 3600
 *       }
 *     }
 *
 * Logged-in admin users bypass the gate automatically.
 */
class MaintenanceMiddleware implements PageMiddlewareInterface
{
	public const DEFAULT_MESSAGE     = 'This page is temporarily unavailable.';
	public const DEFAULT_RETRY_AFTER = 3600;

	public function __construct(
		private readonly string $defaultMessage = self::DEFAULT_MESSAGE,
		private readonly int $defaultRetryAfter = self::DEFAULT_RETRY_AFTER,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		$config = $page->data['maintenance'] ?? null;
		if (!is_array($config)) {
			return null;
		}

		if ($this->isAdmin($request)) {
			return null;
		}

		$message    = $this->message($config);
		$retryAfter = $this->retryAfter($config);

		return $this->render($message, $retryAfter);
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private function message(array $config): string
	{
		$raw = $config['message'] ?? null;

		return is_string($raw) && trim($raw) !== '' ? trim($raw) : $this->defaultMessage;
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private function retryAfter(array $config): int
	{
		$raw = $config['retryAfter'] ?? null;

		return is_numeric($raw) ? max(0, (int)$raw) : $this->defaultRetryAfter;
	}

	private function isAdmin(ServerRequestInterface $request): bool
	{
		$session = $request->getAttribute('session');
		if (!is_object($session)) {
			return false;
		}

		if (method_exists($session, 'get')) {
			return (bool)$session->get('AUTH_USER');
		}

		return false;
	}

	private function render(string $message, int $retryAfter): ResponseInterface
	{
		$escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		$html = <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>Temporarily Unavailable</title>
		<style>
		*{margin:0;padding:0;box-sizing:border-box}
		body{font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}
		.tcms-maintenance{text-align:center;padding:2rem;max-width:480px}
		.tcms-maintenance h1{font-size:1.25rem;font-weight:600;margin-bottom:1rem}
		.tcms-maintenance p{font-size:1rem;color:#666;line-height:1.5}
		</style>
		</head>
		<body>
		<div class="tcms-maintenance">
		<h1>Temporarily Unavailable</h1>
		<p>{$escapedMessage}</p>
		</div>
		</body>
		</html>
		HTML;

		$psr17 = new Psr17Factory();

		return $psr17->createResponse(503)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withHeader('Retry-After', (string)$retryAfter)
			->withBody($psr17->createStream($html));
	}
}
