<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Maintenance;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;
use TotalCMS\Domain\Twig\Markdown\ParsedownMarkdown;

/**
 * Per-page 503 maintenance mode — takes individual pages offline with a
 * custom message while the rest of the site stays up. Different from T3's
 * site-wide MaintenanceModeMiddleware (which is all-or-nothing).
 *
 * Attaching this middleware to a page (via the page's `middleware` list) is
 * what puts the page into maintenance — using the extension's default message
 * and retry-after. A page may *optionally* override those defaults with a
 * `maintenance` block in its `data` JSON blob; its absence just means "use the
 * defaults", not "disabled":
 *
 *     {
 *       "maintenance": {
 *         "heading":           "Back Soon",
 *         "message":           "This section is being updated. Back at **5pm EST**.",
 *         "retryAfterMinutes": 60
 *       }
 *     }
 *
 * The message is rendered as Markdown (links, emphasis), with raw HTML escaped.
 * Operators can point the extension at a Site Builder template for full control
 * of the page chrome; the built-in layout below is the fallback. Logged-in
 * admin users bypass the gate automatically.
 */
class MaintenanceMiddleware implements PageMiddlewareInterface
{
	public const DEFAULT_MESSAGE             = 'This page is temporarily unavailable.';
	public const DEFAULT_HEADING             = 'Temporarily Unavailable';
	public const DEFAULT_RETRY_AFTER_MINUTES = 60;

	/**
	 * @param \Closure(string, array<string,mixed>): string|null $templateRenderer
	 *        Renders a Site Builder template path + context to HTML. Null (or a
	 *        blank $template) falls back to the built-in layout.
	 * @param \Closure(): bool|null $isAdmin
	 *        Returns true when an admin/operator is logged in, so they preview the
	 *        page instead of the gate. Front-end members (public registration) do
	 *        not count. Null means "no one bypasses" (safe default).
	 */
	public function __construct(
		private readonly string $defaultMessage = self::DEFAULT_MESSAGE,
		private readonly int $defaultRetryAfterMinutes = self::DEFAULT_RETRY_AFTER_MINUTES,
		private readonly string $defaultHeading = self::DEFAULT_HEADING,
		private readonly string $template = '',
		private readonly ?\Closure $templateRenderer = null,
		private readonly ?\Closure $isAdmin = null,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		// The page opted into this middleware, so it's in maintenance. A
		// `maintenance` block in the page data is an optional per-page override
		// of the extension defaults — its absence means "use the defaults", not
		// "disabled".
		$config = $page->data['maintenance'] ?? [];
		if (!is_array($config)) {
			$config = [];
		}

		if ($this->isAdmin()) {
			return null;
		}

		$heading           = $this->heading($config);
		$message           = $this->message($config);
		$retryAfterMinutes = $this->retryAfter($config);
		$retryAfterSeconds = $retryAfterMinutes * 60;
		$messageHtml       = (new ParsedownMarkdown())->convert($message);

		$body = $this->renderCustomTemplate($heading, $message, $messageHtml, $retryAfterMinutes)
			?? $this->renderDefault($heading, $messageHtml);

		$psr17 = new Psr17Factory();

		// Retry-After is an HTTP header, so it must be seconds even though the
		// operator configures the value in minutes.
		return $psr17->createResponse(503)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withHeader('Retry-After', (string)$retryAfterSeconds)
			->withBody($psr17->createStream($body));
	}

	/**
	 * @param array<string,mixed> $config
	 */
	private function heading(array $config): string
	{
		$raw = $config['heading'] ?? null;

		return is_string($raw) && trim($raw) !== '' ? trim($raw) : $this->defaultHeading;
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
		$raw = $config['retryAfterMinutes'] ?? null;

		return is_numeric($raw) ? max(0, (int)$raw) : $this->defaultRetryAfterMinutes;
	}

	private function isAdmin(): bool
	{
		return $this->isAdmin instanceof \Closure && ($this->isAdmin)() === true;
	}

	/**
	 * Render an operator-supplied Site Builder template, when configured.
	 * Returns null to fall back to the built-in layout — maintenance must never
	 * 500, so a missing/broken template (or blank value) degrades gracefully.
	 */
	private function renderCustomTemplate(string $heading, string $message, string $messageHtml, int $retryAfterMinutes): ?string
	{
		if ($this->template === '' || !$this->templateRenderer instanceof \Closure) {
			return null;
		}

		try {
			$html = ($this->templateRenderer)($this->template, [
				'heading'           => $heading,
				'message'           => $messageHtml,  // rendered HTML — use `{{ message|raw }}`
				'messageText'       => $message,      // original Markdown source
				'retryAfterMinutes' => $retryAfterMinutes,
			]);

			return trim($html) !== '' ? $html : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Built-in fallback layout. $messageHtml is already safe — Parsedown runs in
	 * safe mode, so raw HTML in the source is escaped.
	 */
	private function renderDefault(string $heading, string $messageHtml): string
	{
		$escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>{$escapedHeading}</title>
		<style>
		*{margin:0;padding:0;box-sizing:border-box}
		body{font-family:system-ui,-apple-system,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;color:#333}
		.tcms-maintenance{text-align:center;padding:2rem;max-width:480px}
		.tcms-maintenance h1{font-size:1.25rem;font-weight:600;margin-bottom:1rem}
		.tcms-maintenance p{font-size:1rem;color:#666;line-height:1.5}
		.tcms-maintenance a{color:#0066cc}
		</style>
		</head>
		<body>
		<div class="tcms-maintenance">
		<h1>{$escapedHeading}</h1>
		{$messageHtml}
		</div>
		</body>
		</html>
		HTML;
	}
}
