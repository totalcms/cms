<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Development;

use DI\Definition\Exception\InvalidDefinition;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
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

	/** Run the private before_send filter on one exception. */
	private function filter(\Throwable $exception): ?Event
	{
		$method = new \ReflectionMethod(SentryMiddleware::class, 'filterEvent');

		return $method->invoke(null, Event::createEvent(), EventHint::fromArray(['exception' => $exception]));
	}

	public function testDropsAMissingTotalCmsClassEvenWhenItLoadsByFilterTime(): void
	{
		// The cron-during-export race: the classmap include missed while the
		// file was being written, and the class loads fine a moment later.
		// TwigEngine is loadable here, which used to make this report.
		$exception = new DependencyException(
			"Error while injecting dependencies into TotalCMS\\Domain\\DataView\\Service\\DataViewBuilder: No entry or class found for 'TotalCMS\\Domain\\Twig\\Service\\TwigEngine'",
		);

		$this->assertTrue(class_exists('TotalCMS\\Domain\\Twig\\Service\\TwigEngine'));
		$this->assertNull($this->filter($exception));
	}

	public function testReportsAMissingClassOutsideTotalCms(): void
	{
		// A vendor class T3 depends on is not ours to explain away.
		$exception = new NotFoundException("No entry or class found for 'Vendor\\Package\\Thing'");

		$this->assertInstanceOf(Event::class, $this->filter($exception));
	}

	public function testReportsOtherContainerFailuresOnTotalCmsClasses(): void
	{
		// A real wiring bug in a class that loads fails with a different
		// message, and still reports on the CLI.
		$exception = new DependencyException(
			'Error while injecting dependencies into TotalCMS\\Domain\\Foo: Parameter $bar of __construct() has no value defined or guessable',
		);

		$this->assertInstanceOf(Event::class, $this->filter($exception));
	}
}
