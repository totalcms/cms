<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Scheduled;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface;

/**
 * Time-window gate — only renders the page between the configured
 * start and end timestamps. Before the window it redirects to
 * `beforeWindow` (or 404s); after the window, to `afterWindow` (or 404s).
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "scheduledFrom":  "2026-11-25T00:00:00Z",
 *       "scheduledUntil": "2026-12-31T23:59:59Z",
 *       "beforeWindow":   "/coming-soon",
 *       "afterWindow":    "/sale-ended"
 *     }
 *
 * Both bounds and both redirects are optional — open-ended ranges work, and a
 * missing redirect for a given side means a 404 on that side.
 *
 * Logged-in operators bypass the schedule and always see the page, so they can
 * preview a not-yet-live (or already-expired) page — the same way the
 * Maintenance and Protect extensions behave.
 */
class ScheduledMiddleware implements PageMiddlewareInterface
{
	/**
	 * @param \Closure(): bool|null $isAdmin
	 *        Returns true when an admin/operator is logged in, so they preview the
	 *        page instead of the time gate. Front-end members (public registration)
	 *        do not count. Null means "no one bypasses" (safe default).
	 */
	public function __construct(
		private readonly ?\Closure $isAdmin = null,
	) {
	}

	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		// Logged-in operators preview the page regardless of its schedule.
		if ($this->isAdmin()) {
			return null;
		}

		$from  = $this->parseTime($page, 'scheduledFrom');
		$until = $this->parseTime($page, 'scheduledUntil');

		if ($from === null && $until === null) {
			return null;
		}

		$now = $this->now();

		if ($from !== null && $now < $from) {
			// Not live yet — redirect to a "coming soon" page if set, else 404.
			return $this->outsideResponse($page, 'beforeWindow');
		}

		if ($until !== null && $now > $until) {
			// Window has ended — redirect to a fallback (e.g. "sale ended") if set,
			// else 404.
			return $this->outsideResponse($page, 'afterWindow');
		}

		return null;
	}

	private function parseTime(PageData $page, string $key): ?\DateTimeImmutable
	{
		$raw = $page->data[$key] ?? null;
		if (!is_string($raw) || trim($raw) === '') {
			return null;
		}

		try {
			return new \DateTimeImmutable(trim($raw));
		} catch (\Exception) {
			return null;
		}
	}

	private function outsideResponse(PageData $page, string $redirectKey): ResponseInterface
	{
		$redirect = $page->data[$redirectKey] ?? null;
		$psr17    = new Psr17Factory();

		if (is_string($redirect) && trim($redirect) !== '') {
			return $psr17->createResponse(302)
				->withHeader('Location', trim($redirect));
		}

		return $psr17->createResponse(404)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withBody($psr17->createStream(''));
	}

	private function isAdmin(): bool
	{
		return $this->isAdmin instanceof \Closure && ($this->isAdmin)() === true;
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}
}
