<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;

/**
 * The license every test app boots with: a valid Pro license, decided here
 * rather than by license.totalcms.co. The real validator asked the license
 * server on every boot, since each test clears the cache it would have used.
 * Tests of the validator itself build the real class.
 */
class FakeLicenseValidator extends LicenseValidator
{
	public function validateLicense(bool $forceRefresh = false): LicenseData
	{
		return new LicenseData(
			valid             : true,
			trial             : false,
			domain            : '',
			edition           : Edition::PRO->value,
			message           : 'Test license',
			validationToken   : null,
			updatesValid      : true,
			dnsVerified       : true,
			versionAuthorized : true,
			timestamp         : time(),
		);
	}
}
