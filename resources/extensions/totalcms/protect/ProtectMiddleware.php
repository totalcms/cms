<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Protect;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

/**
 * Gate a page behind a numeric passcode. Visitors who enter the correct
 * code get a cookie so they aren't prompted again.
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "passcode":    "8675",
 *       "promptTitle":  "Enter passcode to view"
 *     }
 *
 * Cookie: `tcms_protect_<pageId>=<hmac>`, 7-day TTL.
 */
class ProtectMiddleware implements PageMiddlewareInterface
{
	public const COOKIE_PREFIX = 'tcms_protect_';
	public const COOKIE_TTL    = 7 * 86400;

	public function __construct(
		private readonly string $defaultPasscode = '',
		private readonly string $defaultPromptTitle = 'Enter passcode to view',
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		$passcode = $this->stringConfig($page, 'passcode') ?: $this->defaultPasscode;
		if ($passcode === '') {
			return null;
		}

		if ($this->hasCookie($request, $page->id, $passcode)) {
			return null;
		}

		if ($request->getMethod() === 'POST') {
			return $this->handleSubmit($request, $page, $passcode);
		}

		return $this->renderPrompt($page, false);
	}

	private function handleSubmit(
		ServerRequestInterface $request,
		PageData $page,
		string $passcode,
	): ResponseInterface {
		$body  = $request->getParsedBody();
		$input = is_array($body) ? trim((string)($body['passcode'] ?? '')) : '';

		if ($input === $passcode) {
			$cookie = $this->cookie($page->id, $passcode);

			return (new Psr17Factory())->createResponse(302)
				->withHeader('Location', $request->getUri()->getPath())
				->withHeader('Set-Cookie', $cookie);
		}

		return $this->renderPrompt($page, true);
	}

	private function hasCookie(ServerRequestInterface $request, string $pageId, string $passcode): bool
	{
		$cookies  = $request->getCookieParams();
		$existing = $cookies[self::COOKIE_PREFIX . $pageId] ?? null;

		if (!is_string($existing) || $existing === '') {
			return false;
		}

		return hash_equals($this->hmac($pageId, $passcode), $existing);
	}

	private function hmac(string $pageId, string $passcode): string
	{
		return hash_hmac('sha256', $passcode, $pageId);
	}

	private function cookie(string $pageId, string $passcode): string
	{
		$expires = gmdate('D, d M Y H:i:s T', time() + self::COOKIE_TTL);

		return sprintf(
			'%s%s=%s; Expires=%s; Path=/; SameSite=Lax; HttpOnly',
			self::COOKIE_PREFIX,
			$pageId,
			$this->hmac($pageId, $passcode),
			$expires,
		);
	}

	private function renderPrompt(PageData $page, bool $error): ResponseInterface
	{
		$title   = $this->stringConfig($page, 'promptTitle') ?: $this->defaultPromptTitle;
		$message = $error ? '<p class="tcms-protect-error">Incorrect passcode.</p>' : '';

		$html = <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>{$title}</title>
		<style>
		*{margin:0;padding:0;box-sizing:border-box}
		body{font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}
		.tcms-protect{text-align:center;padding:2rem;max-width:360px}
		.tcms-protect h1{font-size:1.25rem;font-weight:600;margin-bottom:1.5rem}
		.tcms-protect form{display:flex;flex-direction:column;gap:.75rem}
		.tcms-protect input[type="text"]{font-size:1.5rem;text-align:center;letter-spacing:.5em;padding:.75rem 1rem;border:2px solid #ddd;border-radius:8px;outline:none;font-variant-numeric:tabular-nums}
		.tcms-protect input[type="text"]:focus{border-color:#666}
		.tcms-protect button{padding:.75rem 1.5rem;background:#333;color:#fff;border:none;border-radius:8px;font-size:1rem;cursor:pointer}
		.tcms-protect button:hover{background:#555}
		.tcms-protect-error{color:#c0392b;font-size:.875rem;margin-bottom:.5rem}
		</style>
		</head>
		<body>
		<div class="tcms-protect">
		<h1>{$title}</h1>
		{$message}
		<form method="post">
		<input type="text" name="passcode" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="0000" required autofocus>
		<button type="submit">Submit</button>
		</form>
		</div>
		</body>
		</html>
		HTML;

		$psr17 = new Psr17Factory();

		return $psr17->createResponse(403)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withBody($psr17->createStream($html));
	}

	private function stringConfig(PageData $page, string $key): string
	{
		$value = $page->data[$key] ?? '';

		return is_string($value) ? trim($value) : '';
	}
}
