<?php

namespace TotalCMS\Domain\License\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Data\LicenseStatusData;
use TotalCMS\Factory\LoggerFactory;

/**
 * License status service for sidebar display logic.
 */
readonly class LicenseStatus
{
	private LoggerInterface $logger;

	public function __construct(
		private LicenseValidator $licenseValidator,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('license.log')->createLogger('license');
	}

	/**
	 * Force refresh license data (for license manager page).
	 */
	public function forceRefresh(): void
	{
		try {
			$this->licenseValidator->validateLicense(forceRefresh: true);
		} catch (\Exception $e) {
			// Silently fail - the getSidebarStatus() method will handle
			// the error appropriately when called later
			$this->logger->warning('License force refresh failed: ' . $e->getMessage(), [
				'exception' => $e,
			]);
		}
	}

	/**
	 * Get license status for sidebar display.
	 */
	public function getSidebarStatus(): LicenseStatusData
	{
		try {
			$license = $this->licenseValidator->validateLicense();

			// Licensed and valid with no issues = show nothing
			if ($this->isFullyValid($license)) {
				return new LicenseStatusData(showIcon: false);
			}

			// Updates expired but license valid
			if ($license->edition === 'development') {
				return new LicenseStatusData(
					showIcon : true,
					severity : 'info',
					tooltip  : 'Development license in use. Not for production sites.'
				);
			}

			// Trial logic
			if ($license->trial && $license->trialDaysRemaining !== null) {
				return $this->getTrialStatus($license->trialDaysRemaining);
			}

			// Updates expired but license valid
			if ($license->valid && !$license->updatesValid) {
				return new LicenseStatusData(
					showIcon : true,
					severity : 'warning',
					tooltip  : 'License updates have expired. Some features may be limited.'
				);
			}

			// License blocked/invalid
			if (!$license->valid) {
				return new LicenseStatusData(
					showIcon : true,
					severity : 'error',
					tooltip  : 'License validation failed: ' . $license->message
				);
			}

			// Fallback - show nothing if status unclear
			return new LicenseStatusData(showIcon: false);
		} catch (\Exception) {
			// Network error or cache issue - show offline warning
			return new LicenseStatusData(
				showIcon : true,
				severity : 'warning',
				tooltip  : 'Unable to verify license status. Using cached data.'
			);
		}
	}

	/**
	 * Check if license is fully valid with no warnings.
	 */
	private function isFullyValid(LicenseData $license): bool
	{
		return $license->valid
			&& $license->updatesValid
			&& !$license->trial;
	}

	/**
	 * Get trial status based on days remaining.
	 */
	private function getTrialStatus(int $daysRemaining): LicenseStatusData
	{
		if ($daysRemaining <= 3) {
			return new LicenseStatusData(
				showIcon: true,
				severity: 'error',
				daysRemaining: $daysRemaining,
				tooltip: "Trial expires in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's') . '. Click to purchase a license.'
			);
		}

		if ($daysRemaining <= 7) {
			return new LicenseStatusData(
				showIcon: true,
				severity: 'warning',
				daysRemaining: $daysRemaining,
				tooltip: "Trial expires in {$daysRemaining} days. Click to purchase a license."
			);
		}

		// Trial with 8+ days remaining - show info
		return new LicenseStatusData(
			showIcon: true,
			severity: 'info',
			daysRemaining: $daysRemaining,
			tooltip: "Trial expires in {$daysRemaining} days. Click for license options."
		);
	}

	/**
	 * Check if license is a trial license.
	 */
	public function isTrial(): bool
	{
		try {
			$license = $this->licenseValidator->validateLicense();

			return $license->trial;
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if license is a development license.
	 */
	public function isDevelopment(): bool
	{
		try {
			$license = $this->licenseValidator->validateLicense();

			return $license->edition === 'development';
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Check if edition simulation is allowed.
	 * Only development and trial licenses can simulate editions.
	 */
	public function canSimulateEdition(): bool
	{
		return $this->isTrial() || $this->isDevelopment();
	}

	/**
	 * Get the current license edition.
	 */
	public function getEdition(): string
	{
		try {
			$license = $this->licenseValidator->validateLicense();

			return $license->edition;
		} catch (\Exception) {
			return 'unknown';
		}
	}
}
