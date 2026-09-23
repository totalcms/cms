<?php

declare(strict_types=1);

namespace TotalCMS\Action\Cron;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationTicker;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\ProcessLock;

/**
 * Fires due scheduled automations over HTTP, for hosts whose cron can only fetch
 * a URL. The counterpart to `tcms automations:process`, running the same
 * {@see AutomationTicker} against the same lock file.
 *
 * No time budget here, unlike the job queue. A tick fires whatever schedules are
 * due and returns, and the queue drain is bounded by what is already queued —
 * there is no open-ended backlog to work through. A handler slow enough to time
 * out is a guard-rail problem (AutomationGuard), not a budgeting one.
 */
final readonly class CronAutomationsAction
{
	private const LOCK_FILE = '.system/.processAutomations.lock';

	public function __construct(
		private JsonRenderer $renderer,
		private AutomationTicker $ticker,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$lock = ProcessLock::open(PathUtils::absolutePath($this->config->datadir, self::LOCK_FILE));
		if ($lock === null) {
			return $this->renderer->json($response, ['skipped' => 'no-lock-file'], 200);
		}
		if (!$lock->acquire()) {
			return $this->renderer->json($response, ['skipped' => 'already-running'], 200);
		}

		try {
			return $this->renderer->json($response, $this->ticker->tick(), 200);
		} finally {
			$lock->release();
		}
	}
}
