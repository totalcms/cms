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

	public function testSentryTagsTheCommandWhenItStarts(): void
	{
		// A fatal error (memory, time limit) never reaches the ERROR listener;
		// Sentry's shutdown handler reports it with the scope as it stands, so
		// the command must be on the scope before it runs (TOTAL-CMS-RM, -RQ).
		$this->assertTrue(CliApplication::sentryErrorDispatcher()->hasListeners(ConsoleEvents::COMMAND));
	}
}
