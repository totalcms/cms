<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Development;

use DI\Definition\Exception\InvalidDefinition;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Middleware\Development\SentryMiddleware;

final class SentryMiddlewareTest extends TestCase
{
	public function testWebContextIgnoresContainerExceptions(): void
	{
		$ignored = SentryMiddleware::ignoredExceptions(cli: false);

		$this->assertContains(InvalidDefinition::class, $ignored);
		$this->assertContains(DependencyException::class, $ignored);
		$this->assertContains(NotFoundException::class, $ignored);
	}

	public function testCliContextSurfacesContainerExceptions(): void
	{
		// The jobs:process crash was a DI InvalidDefinition. On cron that's a
		// real, actionable bug we must see — not bot-during-upload web noise.
		$ignored = SentryMiddleware::ignoredExceptions(cli: true);

		$this->assertNotContains(InvalidDefinition::class, $ignored);
		$this->assertNotContains(DependencyException::class, $ignored);
		$this->assertNotContains(NotFoundException::class, $ignored);
	}

	public function testHttpExceptionsIgnoredInBothContexts(): void
	{
		// Always-noise HTTP exceptions stay ignored regardless of context.
		$this->assertContains(HttpNotFoundException::class, SentryMiddleware::ignoredExceptions(cli: false));
		$this->assertContains(HttpNotFoundException::class, SentryMiddleware::ignoredExceptions(cli: true));
	}
}
