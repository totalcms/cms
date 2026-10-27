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
use TotalCMS\Support\PathResolver;

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

	/**
	 * Run the private before_send filter on one exception, with the shipped
	 * options. The exception is made to look thrown from T3's src/ unless
	 * $fromTotalCms is false: built here, it would otherwise come from a test
	 * file, which the filter drops as a site's own script.
	 */
	private function filter(\Throwable $exception, bool $fromTotalCms = true): ?Event
	{
		if ($fromTotalCms) {
			(new \ReflectionProperty($exception, 'file'))->setValue($exception, PathResolver::packageRoot() . '/src/Domain/Fake/Thrower.php');
		}

		// init() normally hands the options to the filter; tests never call it.
		$config = new \ReflectionProperty(SentryMiddleware::class, 'filterConfig');
		$config->setValue(null, (new \ReflectionClassConstant(SentryMiddleware::class, 'DEFAULT_OPTIONS'))->getValue());

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

	public function testDropsAMiddlewareClassASiteAddedThatDoesNotExist(): void
	{
		// Slim's CallableResolver wording for `$app->add(Missing::class)` in a
		// site's own public/index.php (TOTAL-CMS-MZ, 260 events).
		$exception = new \RuntimeException('Callable Coune\\Portal\\Middleware\\GalerieBildMiddleware does not exist');

		$this->assertNull($this->filter($exception));
	}

	public function testDropsALogWriteThatHitTheDiskQuota(): void
	{
		// Monolog's wording when the hosting account is over quota (TOTAL-CMS-QZ).
		$exception = new \UnexpectedValueException('Writing to the log file failed: Write of 4326 bytes failed with errno=122 Disk quota exceeded');

		$this->assertNull($this->filter($exception));
	}

	public function testReportsOtherRuntimeExceptions(): void
	{
		$exception = new \RuntimeException('Callable things are not the problem here, the disk is full');

		$this->assertInstanceOf(Event::class, $this->filter($exception));
	}

	public function testDropsAnExceptionFromASitesOwnScript(): void
	{
		// A customer's tests/keep_opcache_test.php and a scratch `php -S`
		// router reached T3's Sentry (TOTAL-CMS-QN, -R2, -R3): no T3 frame at all.
		$exception = new \RuntimeException('a fault in the code');
		(new \ReflectionProperty($exception, 'file'))->setValue($exception, PathResolver::projectRoot() . '/tests/keep_opcache_test.php');

		$this->assertNull($this->filter($exception, fromTotalCms: false));
	}

	public function testReportsAFatalErrorOutsideTotalCms(): void
	{
		// Fatals carry no trace: one raised inside a library during a T3
		// request has no T3 frame either, and is still ours to see.
		$exception = new \ErrorException('Allowed memory size of 134217728 bytes exhausted', 0, E_ERROR, PathResolver::packageRoot() . '/vendor/twig/twig/src/Environment.php', 10);

		$this->assertInstanceOf(Event::class, $this->filter($exception, fromTotalCms: false));
	}
}
