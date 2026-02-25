<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use Cake\I18n\I18n;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;

final class TotalCMSTwigAdapterLocaleTest extends TestCase
{
	private LocaleTwigAdapter $adapter;

	protected function setUp(): void
	{
		parent::setUp();
		$translator = $this->createMock(TranslationService::class);
		$this->adapter = new LocaleTwigAdapter($translator);
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
		$result = $this->adapter->set('de_DE');

		expect($result)->toBe('');
		expect(\Locale::getDefault())->toBe('de_DE');
	}

	public function testSetLocaleUpdatesCakephpI18nLocale(): void
	{
		$this->adapter->set('fr_FR');

		expect(I18n::getLocale())->toBe('fr_FR');
	}

	public function testGetLocaleReturnsCurrentLocale(): void
	{
		// Set a known locale first
		$this->adapter->set('ja_JP');

		$result = $this->adapter->get();

		expect($result)->toBe('ja_JP');
	}

	public function testSetLocaleReturnsEmptyString(): void
	{
		// The return value should be empty string (for use in Twig templates)
		$result = $this->adapter->set('es_ES');

		expect($result)->toBe('');
		expect($result)->toBeString();
	}

	public function testLocaleCanBeChangedMultipleTimes(): void
	{
		$this->adapter->set('de_DE');
		expect($this->adapter->get())->toBe('de_DE');

		$this->adapter->set('fr_FR');
		expect($this->adapter->get())->toBe('fr_FR');

		$this->adapter->set('ja_JP');
		expect($this->adapter->get())->toBe('ja_JP');
	}

	public function testSetLocaleWithAllSupportedLocales(): void
	{
		$supportedLocales = [
			'en_US', 'en_GB', 'en_CA', 'en_AU', 'en_SG',
			'ar_SA', 'cs_CZ', 'da_DK', 'de_DE',
			'es_ES', 'es_MX', 'fr_FR', 'fr_CA',
			'hu_HU', 'it_IT', 'ja_JP', 'km_KH',
			'nl_NL', 'no_NO', 'pl_PL', 'pt_BR', 'pt_PT',
			'ru_RU', 'tr_TR', 'uk_UA', 'vi_VN', 'zh_CN',
		];

		foreach ($supportedLocales as $locale) {
			$this->adapter->set($locale);
			expect($this->adapter->get())->toBe($locale);
		}
	}

	public function testSetLocaleAffectsBothPhpAndCakephpLocales(): void
	{
		$this->adapter->set('pt_BR');

		// Both should be updated
		expect(\Locale::getDefault())->toBe('pt_BR');
		expect(I18n::getLocale())->toBe('pt_BR');
	}
}
