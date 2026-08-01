<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\LicenseStatusData;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;

/**
 * The licence row on the dashboard's system-status panel.
 *
 * getSidebarStatus() answers "is anything wrong?" and correctly returns nothing
 * when the answer is no — which left the panel rendering an empty badge on every
 * correctly licensed site. These tests pin the two halves apart: a healthy
 * licence must describe itself, and a warning must still pass through untouched.
 *
 * Built without the constructor and given only the collaborators this method
 * uses, matching AdminTwigAdapterDashboardAlertsTest.
 */
final class AdminTwigAdapterLicenseRowTest extends TestCase
{
	/** @return array<string,mixed> */
	private function licenseRow(LicenseStatusData $status, Edition $edition = Edition::PRO, bool $simulating = false): array
	{
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('getEdition')->willReturn($edition);
		$editionFeatures->method('isSimulating')->willReturn($simulating);

		$adapter = (new \ReflectionClass(AdminTwigAdapter::class))->newInstanceWithoutConstructor();
		(new \ReflectionClass($adapter))->getProperty('editionFeatures')->setValue($adapter, $editionFeatures);

		$method = new \ReflectionMethod($adapter, 'dashboardLicenseStatus');
		$method->setAccessible(true);

		/** @var array<string,mixed> $row */
		$row = $method->invoke($adapter, $status);

		return $row;
	}

	public function testHealthyLicenseNamesItsEdition(): void
	{
		// What getSidebarStatus() returns for a fully valid licence: no icon, no
		// tooltip. The panel previously rendered that as an empty badge.
		$row = $this->licenseRow(new LicenseStatusData(showIcon: false));

		self::assertSame('Licensed — Pro', $row['message']);
		self::assertNull($row['daysRemaining']);
	}

	public function testHealthyLicenseUsesAStyledSeverity(): void
	{
		// LicenseStatusData defaults to `info`, and there is no `status-info` badge
		// style — so passing the default through rendered an unstyled chip.
		$row = $this->licenseRow(new LicenseStatusData(showIcon: false));

		self::assertSame('success', $row['severity']);
	}

	public function testSimulatedEditionSaysSo(): void
	{
		// Simulation changes what the site can do; reporting the simulated edition
		// silently would misrepresent the install.
		$row = $this->licenseRow(new LicenseStatusData(showIcon: false), Edition::ENTERPRISE, true);

		self::assertSame('Licensed — Enterprise (simulated)', $row['message']);
	}

	public function testUnknownEditionStillDescribesItself(): void
	{
		$row = $this->licenseRow(new LicenseStatusData(showIcon: false), Edition::UNKNOWN);

		self::assertSame('Licensed', $row['message']);
	}

	public function testWarningPassesThroughUntouched(): void
	{
		// The trial countdown was the one case that already worked. It must keep
		// its own message, severity and day count rather than being overwritten.
		$row = $this->licenseRow(new LicenseStatusData(
			showIcon: true,
			severity: 'warning',
			daysRemaining: 5,
			tooltip: 'Trial expires in 5 days. Click to purchase a license.',
		));

		self::assertSame('warning', $row['severity']);
		self::assertSame('Trial expires in 5 days. Click to purchase a license.', $row['message']);
		self::assertSame(5, $row['daysRemaining']);
	}

	public function testErrorPassesThroughUntouched(): void
	{
		$row = $this->licenseRow(new LicenseStatusData(
			showIcon: true,
			severity: 'error',
			tooltip: 'License validation failed: domain not authorized.',
		));

		self::assertSame('error', $row['severity']);
		self::assertSame('License validation failed: domain not authorized.', $row['message']);
	}
}
