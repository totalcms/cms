<?php

declare(strict_types=1);

namespace Tests\Unit\Bundled\Scheduled;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TotalCMS\Bundled\Scheduled\ScheduledMiddleware;
use TotalCMS\Domain\Builder\Data\PageData;

require_once dirname(__DIR__, 4) . '/resources/extensions/totalcms/scheduled/ScheduledMiddleware.php';

/**
 * Subclass that lets tests control "now" without touching the system clock.
 */
class TestableScheduledMiddleware extends ScheduledMiddleware
{
	private \DateTimeImmutable $fixedNow;

	public function setNow(\DateTimeImmutable $now): void
	{
		$this->fixedNow = $now;
	}

	protected function now(): \DateTimeImmutable
	{
		return $this->fixedNow;
	}
}

final class ScheduledMiddlewareTest extends TestCase
{
	private TestableScheduledMiddleware $middleware;
	private Psr17Factory $psr17;

	protected function setUp(): void
	{
		$this->middleware = new TestableScheduledMiddleware();
		$this->psr17      = new Psr17Factory();
	}

	// --- no-op cases ---

	public function testNoConfigIsNoOp(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-06-15T12:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', []),
		));
	}

	public function testBothBoundsEmptyIsNoOp(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-06-15T12:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', ['scheduledFrom' => '', 'scheduledUntil' => '']),
		));
	}

	public function testMalformedDatesAreIgnored(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-06-15T12:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', ['scheduledFrom' => 'not-a-date', 'scheduledUntil' => 'also-bad']),
		));
	}

	// --- inside window ---

	public function testInsideWindowRendersNormally(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-28T12:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
			]),
		));
	}

	public function testExactlyAtFromBoundaryRendersNormally(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-25T00:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom' => '2026-11-25T00:00:00Z',
			]),
		));
	}

	public function testExactlyAtUntilBoundaryRendersNormally(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-12-31T23:59:59Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledUntil' => '2026-12-31T23:59:59Z',
			]),
		));
	}

	// --- open-ended ranges ---

	public function testFromOnlyLivesForever(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2030-01-01T00:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('launch', [
				'scheduledFrom' => '2026-11-25T00:00:00Z',
			]),
		));
	}

	public function testUntilOnlyWithNoFromRendersBeforeDeadline(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-01-01T00:00:00Z'));

		$this->assertNull($this->middleware->handle(
			$this->request(),
			$this->page('expiring', [
				'scheduledUntil' => '2026-12-31T23:59:59Z',
			]),
		));
	}

	// --- outside window: before → beforeWindow, after → afterWindow ---

	public function testBeforeWindowRedirectsToBeforeWindow(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
				'beforeWindow'   => '/coming-soon',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/coming-soon', $response->getHeaderLine('Location'));
	}

	public function testAfterWindowRedirectsToAfterWindow(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2027-01-02T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
				'afterWindow'    => '/sale-ended',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/sale-ended', $response->getHeaderLine('Location'));
	}

	public function testBeforeWindowIgnoresAfterWindowKey(): void
	{
		// Only afterWindow is set, so a not-yet-started page 404s — it must NOT
		// redirect to the "sale ended" page.
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom' => '2026-11-25T00:00:00Z',
				'afterWindow'   => '/sale-ended',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testAfterWindowIgnoresBeforeWindowKey(): void
	{
		// Only beforeWindow is set, so an expired page 404s rather than bouncing
		// visitors back to the "coming soon" page.
		$this->middleware->setNow(new \DateTimeImmutable('2027-01-02T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledUntil' => '2026-12-31T23:59:59Z',
				'beforeWindow'   => '/coming-soon',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testBeforeWindowWithNoRedirectReturns404(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('launch', ['scheduledFrom' => '2026-11-25T00:00:00Z']),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testAfterWindowWithNoRedirectReturns404(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2027-01-02T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	// --- operator bypass (logged-in operators preview regardless of schedule) ---

	public function testLoggedInOperatorBypassesSchedule(): void
	{
		$mw = new TestableScheduledMiddleware(isAdmin: static fn (): bool => true);
		$mw->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z')); // before the window

		// A page that would 404 for the public (before its window) renders for the operator.
		$this->assertNull($mw->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
			]),
		));
	}

	public function testNonAdminVisitorIsGatedBySchedule(): void
	{
		$mw = new TestableScheduledMiddleware(isAdmin: static fn (): bool => false);
		$mw->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $mw->handle(
			$this->request(),
			$this->page('sale', ['scheduledFrom' => '2026-11-25T00:00:00Z']),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testNoAdminCheckGates(): void
	{
		// Default construction (no isAdmin closure) — the schedule still applies.
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', ['scheduledFrom' => '2026-11-25T00:00:00Z']),
		);

		$this->assertNotNull($response);
		$this->assertSame(404, $response->getStatusCode());
	}

	// --- helpers ---

	/** @param array<string,mixed> $data */
	private function page(string $id, array $data): PageData
	{
		return new PageData(['id' => $id, 'data' => $data]);
	}

	private function request(): \Psr\Http\Message\ServerRequestInterface
	{
		return $this->psr17->createServerRequest('GET', '/sale');
	}
}
