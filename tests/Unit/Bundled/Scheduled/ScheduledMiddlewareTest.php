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

	// --- outside window ---

	public function testBeforeWindowRedirects(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
				'outsideWindow'  => '/sale-ended',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/sale-ended', $response->getHeaderLine('Location'));
	}

	public function testAfterWindowRedirects(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2027-01-02T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('sale', [
				'scheduledFrom'  => '2026-11-25T00:00:00Z',
				'scheduledUntil' => '2026-12-31T23:59:59Z',
				'outsideWindow'  => '/sale-ended',
			]),
		);

		$this->assertNotNull($response);
		$this->assertSame(302, $response->getStatusCode());
		$this->assertSame('/sale-ended', $response->getHeaderLine('Location'));
	}

	public function testOutsideWindowWithNoRedirectReturns404(): void
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

	public function testBeforeFromOnlyReturns404(): void
	{
		$this->middleware->setNow(new \DateTimeImmutable('2026-11-20T00:00:00Z'));

		$response = $this->middleware->handle(
			$this->request(),
			$this->page('launch', [
				'scheduledFrom' => '2026-11-25T00:00:00Z',
			]),
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
