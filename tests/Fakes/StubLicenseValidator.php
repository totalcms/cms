<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\License\Service\LicenseValidator;

/**
 * A license validator that answers with whatever a test gives it, or fails as
 * the real one does when the license server cannot be reached and nothing is
 * cached. Built without the real constructor: it talks to nothing.
 */
class StubLicenseValidator extends LicenseValidator
{
	public ?LicenseData $license = null;

	/**
	 * @param list<mixed> $entitlements as the license server sends them
	 */
	public static function with(string $edition = 'pro', array $entitlements = []): self
	{
		$stub          = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$stub->license = LicenseData::fromApiResponse(['valid' => true, 'edition' => Edition::fromString($edition)->value, 'entitlements' => $entitlements]);

		return $stub;
	}

	/** A trial on a real domain: running, or past its last day. */
	public static function trial(bool $running = true): self
	{
		$stub          = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$stub->license = LicenseData::fromApiResponse(['valid' => $running, 'trial' => true, 'edition' => Edition::TRIAL->value, 'trialDaysRemaining' => $running ? 14 : 0]);

		return $stub;
	}

	/** The license server is down and the cache is empty. */
	public static function failing(): self
	{
		return (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
	}

	public function validateLicense(bool $forceRefresh = false): LicenseData
	{
		return $this->license ?? throw new LicenseException('License server unreachable');
	}
}
