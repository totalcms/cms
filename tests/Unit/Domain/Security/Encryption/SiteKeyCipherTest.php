<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Security\Encryption;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Security\Encryption\SiteKey;

final class SiteKeyCipherTest extends TestCase
{
	protected function setUp(): void
	{
		SiteKey::reset();
	}

	public function testDefaultKeyRoundTrip(): void
	{
		$encrypted = Cipher::encrypt('secret-value');

		expect(Cipher::decrypt($encrypted))->toBe('secret-value');
	}

	public function testLegacySaltCiphertextStillDecryptsWithoutExplicitKey(): void
	{
		// Data encrypted before the per-site key existed used the SALT
		// constant. Keyless decrypt must fall back to it.
		$legacy = Cipher::encrypt('legacy-value', Cipher::SALT);

		expect(Cipher::decrypt($legacy))->toBe('legacy-value');
	}

	public function testExplicitKeyBypassesSiteKeyAndFallback(): void
	{
		$encrypted = Cipher::encrypt('scoped', 'custom-key');

		expect(Cipher::decrypt($encrypted, 'custom-key'))->toBe('scoped');

		$this->expectException(\Exception::class);
		Cipher::decrypt($encrypted, 'wrong-key');
	}

	public function testSiteKeyIsGeneratedInTestDatadirAndMemoized(): void
	{
		$key = SiteKey::get();

		// The test env datadir exists, so a key must be generated and stable.
		expect($key)->not()->toBeNull();
		expect(SiteKey::get())->toBe($key);

		SiteKey::reset();
		expect(SiteKey::get())->toBe($key);
	}

	public function testEncryptUsesSiteKeyWhenAvailable(): void
	{
		$siteKey = SiteKey::get();
		expect($siteKey)->not()->toBeNull();

		$encrypted = Cipher::encrypt('site-keyed');

		// Decryptable with the site key explicitly, but NOT with the shipped
		// constant — proving the default no longer keys to public source.
		expect(Cipher::decrypt($encrypted, (string)$siteKey))->toBe('site-keyed');

		$this->expectException(\Exception::class);
		Cipher::decrypt($encrypted, Cipher::SALT);
	}
}
