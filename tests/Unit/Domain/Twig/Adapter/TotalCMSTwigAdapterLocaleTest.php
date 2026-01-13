<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use Cake\I18n\I18n;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

final class TotalCMSTwigAdapterLocaleTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Reset locale before each test
		\Locale::setDefault('en_US');
		I18n::setLocale('en_US');
	}

	protected function tearDown(): void
	{
		// Reset locale after each test
		\Locale::setDefault('en_US');
		I18n::setLocale('en_US');
		parent::tearDown();
	}

	public function testSetLocaleUpdatesPhpLocale(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$result = $adapter->setLocale('de_DE');

		expect($result)->toBe('');
		expect(\Locale::getDefault())->toBe('de_DE');
	}

	public function testSetLocaleUpdatesCakephpI18nLocale(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$adapter->setLocale('fr_FR');

		expect(I18n::getLocale())->toBe('fr_FR');
	}

	public function testGetLocaleReturnsCurrentLocale(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// Set a known locale first
		$adapter->setLocale('ja_JP');

		$result = $adapter->getLocale();

		expect($result)->toBe('ja_JP');
	}

	public function testSetLocaleReturnsEmptyString(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// The return value should be empty string (for use in Twig templates)
		$result = $adapter->setLocale('es_ES');

		expect($result)->toBe('');
		expect($result)->toBeString();
	}

	public function testLocaleCanBeChangedMultipleTimes(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$adapter->setLocale('de_DE');
		expect($adapter->getLocale())->toBe('de_DE');

		$adapter->setLocale('fr_FR');
		expect($adapter->getLocale())->toBe('fr_FR');

		$adapter->setLocale('ja_JP');
		expect($adapter->getLocale())->toBe('ja_JP');
	}

	public function testSetLocaleWithAllSupportedLocales(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$supportedLocales = [
			'en_US', 'en_GB', 'en_CA', 'en_AU', 'en_SG',
			'ar_SA', 'cs_CZ', 'da_DK', 'de_DE',
			'es_ES', 'es_MX', 'fr_FR', 'fr_CA',
			'hu_HU', 'it_IT', 'ja_JP', 'km_KH',
			'nl_NL', 'no_NO', 'pl_PL', 'pt_BR', 'pt_PT',
			'ru_RU', 'tr_TR', 'uk_UA', 'vi_VN', 'zh_CN',
		];

		foreach ($supportedLocales as $locale) {
			$adapter->setLocale($locale);
			expect($adapter->getLocale())->toBe($locale);
		}
	}

	public function testSetLocaleAffectsBothPhpAndCakephpLocales(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		$adapter->setLocale('pt_BR');

		// Both should be updated
		expect(\Locale::getDefault())->toBe('pt_BR');
		expect(I18n::getLocale())->toBe('pt_BR');
	}
}
