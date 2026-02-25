<?php

namespace Tests\Unit\Domain\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

final class TranslationServiceTest extends TestCase
{
	private string $translationsPath;

	protected function setUp(): void
	{
		$this->translationsPath = dirname(__DIR__, 4) . '/resources/translations';
	}

	private function createService(string $locale = ''): TranslationService
	{
		$config = $this->createMock(Config::class);
		$config->locale = $locale;

		return new TranslationService($config, $this->translationsPath);
	}

	// ── Default / English ───────────────────────────────────────────────────

	public function testDefaultLocaleUsesEnglish(): void
	{
		$service = $this->createService();
		$this->assertSame('Save', $service->trans('btn.save'));
	}

	public function testBasicTranslationWorks(): void
	{
		$service = $this->createService('en_US');
		$this->assertSame('Save', $service->trans('btn.save'));
	}

	public function testExplicitAdminDomain(): void
	{
		$service = $this->createService('en_US');
		$this->assertSame('Save', $service->trans('btn.save', [], 'admin'));
	}

	public function testMissingKeyReturnsKeyItself(): void
	{
		$service = $this->createService('en_US');
		$this->assertSame('nonexistent.key', $service->trans('nonexistent.key'));
	}

	public function testParameterSubstitution(): void
	{
		$service = $this->createService('en_US');
		$result = $service->trans('dashboard.welcome_back', ['{name}' => 'Joe']);
		$this->assertSame('Welcome back, Joe!', $result);
	}

	// ── Other Locales ───────────────────────────────────────────────────────

	public function testGermanLocale(): void
	{
		$service = $this->createService('de_DE');
		$this->assertSame('Speichern', $service->trans('btn.save'));
	}

	public function testSpanishLocale(): void
	{
		$service = $this->createService('es_ES');
		$this->assertSame('Guardar', $service->trans('btn.save'));
	}

	public function testDutchLocale(): void
	{
		$service = $this->createService('nl_NL');
		$this->assertSame('Opslaan', $service->trans('btn.save'));
	}

	public function testBritishEnglishDifferences(): void
	{
		$service = $this->createService('en_GB');
		$this->assertSame('Text Colour', $service->trans('imageworks.text_color'));
	}

	// ── Fallback ────────────────────────────────────────────────────────────

	public function testFallbackForUnknownLocale(): void
	{
		$service = $this->createService('fr_FR');
		$this->assertSame('Save', $service->trans('btn.save'));
	}

	// ── Catalogs ────────────────────────────────────────────────────────────

	public function testJsCatalogReturnsArray(): void
	{
		$service = $this->createService('en_US');
		$catalog = $service->getCatalog('js');
		$this->assertIsArray($catalog);
		$this->assertArrayHasKey('confirm.delete_image', $catalog);
	}

	public function testJsCatalogHasEntries(): void
	{
		$service = $this->createService('en_US');
		$catalog = $service->getCatalog('js');
		$this->assertGreaterThanOrEqual(10, count($catalog));
	}

	public function testJsCatalogFallbackForUnsupportedLocale(): void
	{
		$service = $this->createService('fr_FR');
		$catalog = $service->getCatalog('js');
		$this->assertNotEmpty($catalog);
		$this->assertArrayHasKey('confirm.delete_image', $catalog);
	}

	public function testAdminCatalogSize(): void
	{
		$service = $this->createService('en_US');
		$catalog = $service->getCatalog('admin');
		$this->assertGreaterThanOrEqual(800, count($catalog));
	}

	// ── Translator Access ───────────────────────────────────────────────────

	public function testGetTranslatorReturnsSymfonyTranslator(): void
	{
		$service = $this->createService('en_US');
		$this->assertInstanceOf(Translator::class, $service->getTranslator());
	}

	// ── Consistency ─────────────────────────────────────────────────────────

	public function testAllLocaleFilesHaveSameKeyCount(): void
	{
		$locales = ['en_US', 'en_GB', 'de_DE', 'es_ES', 'nl_NL'];
		$counts = [];

		foreach ($locales as $locale) {
			$file = $this->translationsPath . "/admin.{$locale}.php";
			$translations = require $file;
			$counts[$locale] = count($translations);
		}

		$expected = $counts['en_US'];
		foreach ($counts as $locale => $count) {
			$this->assertSame(
				$expected,
				$count,
				"Locale {$locale} has {$count} keys, expected {$expected} (matching en_US)"
			);
		}
	}
}
