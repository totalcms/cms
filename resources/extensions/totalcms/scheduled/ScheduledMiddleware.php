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
 * start and end timestamps. Outside the window, redirects to a
 * fallback URL or returns 404.
 *
 * Per-page configuration lives in the page's `data` JSON blob:
 *
 *     {
 *       "scheduledFrom":  "2026-11-25T00:00:00Z",
 *       "scheduledUntil": "2026-12-31T23:59:59Z",
 *       "outsideWindow":  "/sale-ended"
 *     }
 *
 * Both bounds are optional — open-ended ranges work.
 */
class ScheduledMiddleware implements PageMiddlewareInterface
{
	public function handle(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		$from  = $this->parseTime($page, 'scheduledFrom');
		$until = $this->parseTime($page, 'scheduledUntil');

		if ($from === null && $until === null) {
			return null;
		}

		$now = $this->now();

		if ($from !== null && $now < $from) {
			return $this->outsideResponse($page);
		}

		if ($until !== null && $now > $until) {
			return $this->outsideResponse($page);
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

	private function outsideResponse(PageData $page): ResponseInterface
	{
		$redirect = $page->data['outsideWindow'] ?? null;
		$psr17    = new Psr17Factory();

		if (is_string($redirect) && trim($redirect) !== '') {
			return $psr17->createResponse(302)
				->withHeader('Location', trim($redirect));
		}

		return $psr17->createResponse(404)
			->withHeader('Content-Type', 'text/html; charset=utf-8')
			->withBody($psr17->createStream(''));
	}

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}
}
