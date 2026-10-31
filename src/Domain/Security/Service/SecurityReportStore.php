<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Service;

use TotalCMS\Domain\Security\Data\SecurityReport;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;

/**
 * Keeps the last security report so the dashboard can show it without
 * re-running the checks. Callers that run an audit save it; the dashboard
 * only reads.
 *
 * A file in the install's own `.system` folder rather than the cache, so the
 * report belongs to this install only and the CLI and the web server (which
 * can have different cache backends) read the same one.
 */
readonly class SecurityReportStore
{
	public const FILE    = '.system/security-report.json';
	private const MAX_AGE = 86400;

	public function __construct(private AtomicJsonStore $store)
	{
	}

	/**
	 * @SuppressWarnings("PHPMD.EmptyCatchBlock") the report only feeds the dashboard alert; an unwritable
	 * .system folder must not take down the Server Checker page or `tcms check` with it
	 */
	public function save(SecurityReport $report): void
	{
		try {
			$this->store->save(self::FILE, $report->toArray());
		} catch (\Throwable) {
		}
	}

	/** The last report, or null when there is none or it is more than 24 hours old. */
	public function latest(): ?SecurityReport
	{
		// TreatAsEmpty: the report is disposable; the next audit rewrites it.
		$data = $this->store->load(self::FILE, CorruptPolicy::TreatAsEmpty);
		if ($data === []) {
			return null;
		}

		$report  = SecurityReport::fromArray($data);
		$checked = strtotime($report->checkedAt);
		if ($checked === false || $checked < time() - self::MAX_AGE) {
			return null;
		}

		return $report;
	}
}
