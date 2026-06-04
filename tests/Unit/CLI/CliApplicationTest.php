<?php

declare(strict_types=1);

namespace Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use TotalCMS\CLI\CliApplication;

final class CliApplicationTest extends TestCase
{
	public function testSentryErrorDispatcherListensForConsoleErrors(): void
	{
		$dispatcher = CliApplication::sentryErrorDispatcher();

		// The ConsoleEvents::ERROR hook is the only seam that forwards a failing
		// CLI command's exception to Sentry — Symfony Console otherwise renders
		// it to stderr and drops it. This is what was missing when jobs:process
		// crashed silently on cron.
		$this->assertTrue($dispatcher->hasListeners(ConsoleEvents::ERROR));
	}
}
