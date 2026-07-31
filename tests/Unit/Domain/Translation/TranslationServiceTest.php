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
		$config         = $this->createMock(Config::class);
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
		$result  = $service->trans('dashboard.welcome_back', ['{name}' => 'Joe']);
		$this->assertSame('Welcome back, Joe!', $result);
	}

	public function testBareParameterNamesResolveDelimitedPlaceholders(): void
	{
		// The regression that shipped: callers pass bare names ({user: id}),
		// strings delimit placeholders ({user}), and the underlying
		// translator substitutes keys verbatim — so the OAuth consent screen
		// rendered 'You are signed in as: %admin%' (and, post-standardization,
		// '{admin}'). trans() must normalize bare keys onto the delimiters.
		$service = $this->createService('en_US');

		$this->assertSame(
			'You are signed in as: admin',
			$service->trans('oauth.consent.signed_in_as', ['user' => 'admin']),
		);
		$this->assertSame(
			'Welcome back, Joe!',
			$service->trans('dashboard.welcome_back', ['name' => 'Joe']),
		);
	}

	public function testBareParameterNeverEatsLiteralWordsInTheString(): void
	{
		// 'Page {page} of {total} ({entries} entries)' — a raw bare key would
		// also replace the literal word 'entries', yielding '(100 100)'.
		$service = $this->createService('en_US');

		$this->assertSame(
			'Page 2 of 5 (100 entries)',
			$service->trans('orphan.page_of', ['page' => 2, 'total' => 5, 'entries' => 100]),
		);
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

	// ── Region fall-down ──────────────────────────────────────────────────────

	public function testBareLanguageCodeFallsDownToRegionFile(): void
	{
		// A bare `es` has no admin.es.php; it should load admin.es_ES.php so the
		// admin UI is Spanish rather than silently falling back to English.
		$service = $this->createService('es');
		$this->assertSame('Guardar', $service->trans('btn.save'));
	}

	public function testUnshippedRegionFallsDownToShippedRegion(): void
	{
		// es_AR has no file; falls down to the first es_* file (es_ES).
		$service = $this->createService('es_AR');
		$this->assertSame('Guardar', $service->trans('btn.save'));
	}

	public function testBareGermanFallsDownToGerman(): void
	{
		$service = $this->createService('de');
		$this->assertSame('Speichern', $service->trans('btn.save'));
	}

	public function testBareLanguageWithNoShippedFileFallsBackToEnglish(): void
	{
		// No French file ships at all, bare or regional.
		$service = $this->createService('fr');
		$this->assertSame('Save', $service->trans('btn.save'));
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
		$counts  = [];

		foreach ($locales as $locale) {
			$file            = $this->translationsPath . "/admin.{$locale}.php";
			$translations    = require $file;
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
