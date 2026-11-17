<?php

declare(strict_types=1);

namespace Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use TotalCMS\CLI\CliApplication;
use TotalCMS\Middleware\Development\SentryMiddleware;

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

	public function testSentryTagsWhetherTheCommandIsAnExtensions(): void
	{
		// A fatal during an extension's command is the extension's (TOTAL-CMS-RW);
		// SentryMiddleware reads this tag to drop it.
		$extensionCommands = new \ReflectionProperty(CliApplication::class, 'extensionCommands');
		$extensionCommands->setValue(null, ['site:copy' => true]);

		try {
			$this->assertSame('extension', $this->commandSourceAfterStarting('site:copy'));
			$this->assertSame('core', $this->commandSourceAfterStarting('jobs:process'));
		} finally {
			$extensionCommands->setValue(null, []);
			SentrySdk::getCurrentHub()->configureScope(static fn (Scope $scope) => $scope->clear());
		}
	}

	private function commandSourceAfterStarting(string $name): ?string
	{
		CliApplication::sentryErrorDispatcher()->dispatch(
			new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput()),
			ConsoleEvents::COMMAND,
		);

		$tags = [];
		SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use (&$tags): void {
			$tags = $scope->applyToEvent(Event::createEvent())?->getTags() ?? [];
		});

		return $tags[SentryMiddleware::COMMAND_SOURCE_TAG] ?? null;
	}
}
