<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Cron\Service\CronTokenProvider;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * The job-queue and cron panels behind `cms.admin.jobQueue*()`,
 * `cms.admin.*Command()` and `cms.admin.cronUrl()`: pending/failed job
 * summaries, the `tcms` command lines an operator pastes into cron, and the
 * tokenised HTTP cron URLs.
 *
 * AdminTwigAdapter is the Twig-facing entry point and delegates here.
 */
readonly class JobQueueRenderer
{
	public function __construct(
		private Config $config,
		private JobManager $jobManager,
		private CronTokenProvider $cronTokens,
	) {
	}

	/**
	 * Prefix shared by every cron-displayable `tcms` command — the absolute
	 * PHP binary + the absolute path to the `tcms` executable, with an
	 * `APP_ENV=<value>` wedge only when a real APP_ENV is in play.
	 *
	 * We key off `appEnv` (the actual env-var value), NOT the merged `env`: when
	 * env came from the settings.json UI toggle there is no APP_ENV to reproduce,
	 * and the CLI resolves the same settings.json on its own. Injecting
	 * `APP_ENV=dev` there would wrongly load `config/local.dev.php` in the cron.
	 * Concrete commands (jobs:process, rss:import, …) append their own arguments.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function tcmsCommandPrefix(): string
	{
		// Only the CLI's PHP_BINARY is a usable interpreter path; under FPM or
		// mod_php it names the server binary, which is wrong for a cron line.
		// (The old `defined(PHP_BINARY)` passed the value, not the name, so this
		// was always 'php' — which is what a web request should still show.)
		$phpPath = PHP_SAPI === 'cli' ? PHP_BINARY : 'php';
		// Composer installs must use the generated bin proxy (vendor/bin/tcms); zip
		// installs use the shipped resources/bin/tcms. PathResolver::tcmsBinary()
		// picks the right one from the package path structure — unlike
		// isComposerInstall(), it does not depend on TCMS_PROJECT_ROOT being
		// defined, which it isn't during a plain web request.
		$command = PathResolver::tcmsBinary();

		// Quote path if it contains spaces
		$quotedCommand = str_contains($command, ' ') ? '"' . $command . '"' : $command;

		$envPrefix = $this->config->appEnv !== '' ? 'APP_ENV=' . $this->config->appEnv . ' ' : '';

		return sprintf('%s%s %s', $envPrefix, $phpPath, $quotedCommand);
	}

	public function processJobQueueCommand(): string
	{
		return $this->tcmsCommandPrefix() . ' jobs:process';
	}

	public function processAutomationsCommand(): string
	{
		return $this->tcmsCommandPrefix() . ' automations:process';
	}

	public function oauthSetupCommand(): string
	{
		return $this->tcmsCommandPrefix() . ' oauth:setup';
	}

	/**
	 * URL for an HTTP cron endpoint, with the token embedded.
	 *
	 * Absolute, because the whole point is pasting it into a host's cron box or
	 * an external cron service — a relative path is useless there. `url` carries
	 * the scheme and domain, `api` the base path, so a subdirectory install gets
	 * a working URL too. Not under the `/api` prefix: these routes are public.
	 *
	 * Calls tokenOrCreate(): the token comes into existence the first time an
	 * operator views the panel that needs it, which is why no setup command
	 * exists. Rendering the page is therefore what mints it — a deliberate trade
	 * against adding a route and a button purely to defer a file write that costs
	 * nothing and grants nothing on its own.
	 *
	 * @param string $task `jobs` or `automations`
	 */
	public function cronUrl(string $task): string
	{
		return sprintf(
			'%s%s/cron/%s?token=%s',
			rtrim($this->config->url, '/'),
			$this->config->api,
			$task,
			$this->cronTokens->tokenOrCreate()
		);
	}

	/**
	 * Get pending jobs info for display.
	 */
	public function jobQueuePendingInfo(): string
	{
		$pendingJobs = $this->jobManager->getPendingJobs();

		if ($pendingJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($pendingJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars($this->formatJobDate($job->createdAt))
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Pending Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview pending-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Created</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
	}

	/**
	 * Job timestamps are stored UTC (SQLite CURRENT_TIMESTAMP). Display them in
	 * the site's configured timezone so the queue manager shows local time.
	 */
	private function formatJobDate(string $utcDate): string
	{
		return DateData::utcToTimezone($utcDate, $this->config->timezone);
	}

	/**
	 * Get failed jobs info for display.
	 */
	public function jobQueueFailedInfo(): string
	{
		$failedJobs = $this->jobManager->getFailedJobs();

		if ($failedJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($failedJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			// Truncate error message for display
			$errorSnippet = $job->lastError;
			if (strlen($errorSnippet) > 100) {
				$errorSnippet = substr($errorSnippet, 0, 100) . '...';
			}

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td title="%s">%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars(strval($job->attempts)),
				htmlspecialchars($job->lastError),
				htmlspecialchars($errorSnippet)
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Failed Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview failed-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Attempts</th>
								<th>Error</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
	}
}
