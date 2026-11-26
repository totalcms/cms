<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Support\Config;

/**
 * Gets a site ready for the `shop` starter: installs the store's
 * collections, and fills in the two store settings a membership needs.
 *
 * A setting the owner has already chosen is never changed. On a site
 * without Commerce nothing is done, and the reason is given.
 *
 * Not final, so a test can put one in its place.
 */
class CommerceStarterSetup
{
	/** The collection the starter's customers sign in to, and the group its membership gives. */
	public const CUSTOMERS = 'customers';
	public const MEMBERS   = 'members';

	public function __construct(
		private readonly CommerceGate $gate,
		private readonly CommerceInstaller $installer,
		private readonly SettingsSaver $settings,
		private readonly Config $config,
	) {
	}

	/** @return string why the store could not be made ready, or '' when it is */
	public function prepare(): string
	{
		$status = $this->gate->status();
		if ($status !== CommerceGateStatus::Allowed) {
			return $status->refusal();
		}

		$report = $this->installer->install();
		if (!$report->ok()) {
			return 'Commerce could not be installed: ' . implode(' ', $report->toArray()['errors']);
		}

		$chosen = $this->config->commerce;
		$fill   = [];
		if (($chosen['customerCollection'] ?? '') === '') {
			$fill['customerCollection'] = self::CUSTOMERS;
		}
		if (($chosen['grantableGroups'] ?? []) === []) {
			$fill['grantableGroups'] = [self::MEMBERS];
		}
		if ($fill !== []) {
			$this->settings->saveSection('commerce', $fill);
			// For the rest of this run: the saved file is read at the next boot.
			$this->config->commerce = $fill + $chosen;
		}

		return '';
	}
}
