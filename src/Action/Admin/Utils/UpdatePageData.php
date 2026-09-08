<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Update\Service\UpdateApplier;
use TotalCMS\Domain\Update\Service\UpdateChecker;

/** The Update utility: latest-version check plus the retained pre-update backup. */
final readonly class UpdatePageData implements UtilsPageData
{
	public function __construct(
		private UpdateChecker $updateChecker,
		private UpdateApplier $updateApplier,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$forceCheck = ($request->getQueryParams()['check'] ?? '') === '1';
		$updateInfo = null;
		try {
			$updateInfo = $this->updateChecker->checkForUpdate($forceCheck);
		} catch (\Throwable) {
			// Silently fail — update check is not critical
		}

		return [
			'updateInfo'     => $updateInfo,
			// The copy of the previous version a successful update kept, so the
			// page can show that it exists and offer to reclaim the disk.
			'retainedBackup' => $this->updateApplier->retainedBackup(),
		];
	}
}
