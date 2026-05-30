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
 *       "passcode":     "8675",
 *       "promptTitle":  "Enter passcode to view",
 *       "protectScope": "client-preview"
 *     }
 *
 * Cookie: `tcms_protect_<scope>=<hmac>`, lifetime configurable (default 7 days).
 *
 * The `scope` is the page's own id by default, so each page is gated
 * independently. Set a shared `protectScope` group id on several pages to let
 * one passcode unlock the whole group from a single cookie — enter the code on
 * any page in the group, the rest unlock too (they must share the passcode).
 *
 * The HMAC uses a per-install secret (32 random bytes stored in
 * `.system/protect/secret`) so cookie values are bound to this
 * installation and cannot be computed from public information alone.
 * The message is `<scope>:passcode` so a valid cookie for one scope
 * cannot be replayed against a different one.
 *
 * Attaching this middleware to a page is what gates it. A passcode is an
 * optional unlock code, NOT a required on-switch: with no passcode configured
 * the page fails CLOSED (the public sees the prompt but can never enter a code
 * that doesn't exist — effectively operators-only). Logged-in operators always
 * bypass the gate to preview the page, the same way the Maintenance extension
 * behaves.
 */
class ProtectMiddleware implements PageMiddlewareInterface
{
	public const COOKIE_PREFIX    = 'tcms_protect_';
	public const COOKIE_TTL       = 7 * 86400;
	public const GLOBAL_SCOPE_KEY = 'global';

	/**
	 * @param \Closure(): bool|null $isAdmin
	 *        Returns true when an admin/operator is logged in, so they preview the
	 *        page instead of the gate. Front-end members (public registration) do
	 *        not count. Null means "no one bypasses" (safe default).
	 * @param int $cookieTtl
	 *        How long (in seconds) the unlock cookie lasts. `0` or less makes it a
	 *        session cookie that the browser drops when it closes.
	 * @param bool $globalScope
	 *        Site-wide mode: every protected page shares one scope cookie and the
	 *        default passcode, so a single code unlocks the whole site. Per-page
	 *        passcodes and `protectScope` groups are ignored while this is on.
	 */
	public function __construct(
		private readonly string $secret,
		private readonly string $defaultPasscode = '',
		private readonly string $defaultPromptTitle = 'Enter passcode to view',
		private readonly ?\Closure $isAdmin = null,
		private readonly int $cookieTtl = self::COOKIE_TTL,
		private readonly bool $globalScope = false,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		// Logged-in operators preview the page instead of the gate, like Maintenance.
		if ($this->isAdmin()) {
			return null;
		}

		$passcode = $this->passcode($page);

		// Attached but no passcode configured: fail closed. There is no valid code
		// to enter, so the public is blocked while operators (above) still preview.
		if ($passcode === '') {
			return $this->renderPrompt($page, false);
		}

		$key = $this->scopeKey($page);

		if ($this->hasCookie($request, $key, $passcode)) {
			return null;
		}

		if ($request->getMethod() === 'POST') {
			return $this->handleSubmit($request, $page, $key, $passcode);
		}

		return $this->renderPrompt($page, false);
	}

	/**
	 * The effective passcode. Site-wide mode always uses the extension default
	 * (one code for the whole site); otherwise a page's own passcode wins, falling
	 * back to the default.
	 */
	private function passcode(PageData $page): string
	{
		if ($this->globalScope) {
			return $this->defaultPasscode;
		}

		return $this->stringConfig($page, 'passcode') ?: $this->defaultPasscode;
	}

	/**
	 * The cookie scope. In site-wide mode every page shares one fixed scope, so a
	 * single cookie unlocks the whole site. Otherwise, when a page sets a
	 * `protectScope` group id, every page in that group shares one unlock cookie —
	 * enter the passcode once, unlock them all (provided they share the same
	 * passcode). Without a scope it falls back to the page's own id, so each page
	 * is gated independently (the default).
	 *
	 * The scope is reduced to a cookie-name-safe token; an empty result (e.g. a
	 * scope of only spaces/symbols) falls back to the page id.
	 */
	private function scopeKey(PageData $page): string
	{
		if ($this->globalScope) {
			return self::GLOBAL_SCOPE_KEY;
		}

		$scope = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $this->stringConfig($page, 'protectScope'));

		return $scope !== '' ? $scope : $page->id;
	}

	private function isAdmin(): bool
	{
		return $this->isAdmin instanceof \Closure && ($this->isAdmin)() === true;
	}

	private function handleSubmit(
		ServerRequestInterface $request,
		PageData $page,
		string $key,
		string $passcode,
	): ResponseInterface {
		$body  = $request->getParsedBody();
		$input = is_array($body) ? trim((string)($body['passcode'] ?? '')) : '';

		if ($input === $passcode) {
			$cookie = $this->cookie($request, $key, $passcode);

			return (new Psr17Factory())->createResponse(302)
				->withHeader('Location', $request->getUri()->getPath())
				->withHeader('Set-Cookie', $cookie);
		}

		return $this->renderPrompt($page, true);
	}

	private function hasCookie(ServerRequestInterface $request, string $key, string $passcode): bool
	{
		$cookies  = $request->getCookieParams();
		$existing = $cookies[self::COOKIE_PREFIX . $key] ?? null;

		if (!is_string($existing) || $existing === '') {
			return false;
		}

		return hash_equals($this->hmac($key, $passcode), $existing);
	}

	/**
	 * Compute the HMAC for a (scope key, passcode) pair.
	 *
	 * Key:     per-install secret (loaded from .system/protect/secret)
	 * Message: "<key>:passcode" — binds the token to both the scope (the page's
	 *          own id, or a shared `protectScope` group) and the correct passcode.
	 *          A cookie for one scope cannot be replayed against a different one.
	 */
	private function hmac(string $key, string $passcode): string
	{
		return hash_hmac('sha256', $key . ':' . $passcode, $this->secret);
	}

	private function cookie(ServerRequestInterface $request, string $key, string $passcode): string
	{
		$isHttps = $request->getUri()->getScheme() === 'https';
		$secure  = $isHttps ? '; Secure' : '';

		// A non-positive TTL means a session cookie: omit Expires so the browser
		// drops it when it closes. Otherwise expire it $cookieTtl seconds out.
		$expires = $this->cookieTtl > 0
			? '; Expires=' . gmdate('D, d M Y H:i:s T', time() + $this->cookieTtl)
			: '';

		return sprintf(
			'%s%s=%s%s; Path=/; SameSite=Lax; HttpOnly%s',
			self::COOKIE_PREFIX,
			$key,
			$this->hmac($key, $passcode),
			$expires,
			$secure,
		);
	}

	private function renderPrompt(PageData $page, bool $error): ResponseInterface
	{
		$rawTitle = $this->stringConfig($page, 'promptTitle') ?: $this->defaultPromptTitle;
		$title    = htmlspecialchars($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$message  = $error ? '<p class="tcms-protect-error">Incorrect passcode.</p>' : '';

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
