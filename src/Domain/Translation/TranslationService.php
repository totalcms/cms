<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Translation;

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use TotalCMS\Domain\Twig\Adapter\LocaleTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Translation service wrapping Symfony Translator.
 *
 * Loads PHP array files from resources/translations/ for OPcache-friendly performance.
 */
class TranslationService
{
	private const MAX_LOCALE_LENGTH = 35;
	private const LOCALE_PATTERN    = '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/';

	private readonly Translator $translator;

	/** @var array<string,true> locales whose resources are already added, so a switch never re-adds them */
	private array $loadedLocales = [];

	private bool $englishLoaded = false;

	public function __construct(
		private readonly Config $config,
		private readonly string $translationsPath,
	) {
		$locale = $this->config->locale !== '' ? $this->config->locale : 'en_US';

		$this->translator = new Translator($locale);
		$this->translator->setFallbackLocales(['en_US']);
		$this->translator->addLoader('php', new PhpFileLoader());

		$this->loadTranslations($locale);
	}

	/**
	 * Translate a key from a given domain.
	 *
	 * Callers pass parameters by bare name ({user: id}); translation strings
	 * delimit their placeholders — {user} is the house style, %user% is
	 * still honored for extension strings — and the underlying translator
	 * substitutes keys VERBATIM. A raw bare key would replace the letters
	 * inside the delimiters ('{user}' → '{admin}') and any literal
	 * occurrence of the word in the string ('{entries} entries' →
	 * '100 100'). So bare keys are swapped for both delimited forms and
	 * never passed through raw; call sites that already pass explicit
	 * delimiters (e.g. {'{label}': …}) are forwarded untouched.
	 *
	 * This normalization lives HERE — the one choke point — because `t()`
	 * reaches the translator through several doors (TotalCMSTwigExtension's
	 * global `t()`, LocaleTwigAdapter::t(), direct PHP callers), and fixing
	 * only one of them left the others substituting verbatim.
	 *
	 * @param array<string,string> $parameters
	 */
	public function trans(string $key, array $parameters = [], string $domain = 'admin'): string
	{
		$normalized = [];
		foreach ($parameters as $name => $value) {
			if (!str_contains((string)$name, '{') && !str_contains((string)$name, '%')) {
				$normalized['{' . $name . '}'] = $value;
				$normalized['%' . $name . '%'] = $value;
			} else {
				$normalized[$name] = $value;
			}
		}

		return $this->translator->trans($key, $normalized, $domain);
	}

	/**
	 * Get all translations for a domain as a flat array.
	 * Useful for passing to JavaScript.
	 *
	 * @return array<string,string>
	 */
	public function getCatalog(string $domain = 'js', ?string $locale = null): array
	{
		$locale ??= $this->translator->getLocale();
		$catalogue = $this->translator->getCatalogue($locale);
		$messages  = $catalogue->all($domain);

		// Fall back to default locale if empty
		if ($messages === [] && $locale !== 'en_US') {
			$catalogue = $this->translator->getCatalogue('en_US');
			$messages  = $catalogue->all($domain);
		}

		return $messages;
	}

	/**
	 * Switch locale at runtime. Loads translations for the new locale if not already loaded.
	 */
	public function setLocale(string $locale): void
	{
		$this->loadTranslations($locale);
		$this->translator->setLocale($locale);
	}

	/**
	 * True when Total CMS ships its own text for this locale: an exact file, a
	 * file for the same language (es → es_ES), or English, which is built in.
	 */
	public function hasLocale(string $locale): bool
	{
		return $locale === 'en_US' || $this->resolveTranslationFile('admin', $locale) !== null;
	}

	/**
	 * Validate an untrusted locale (a request header, a template argument) and
	 * return the locale to switch to, or null to leave the translator alone.
	 *
	 * The value must look like a locale and be one Total CMS ships text for.
	 * A bare language that matches the language of the active locale keeps the
	 * active one, so `en` on an en_US site stays en_US instead of picking the
	 * en_GB file. An explicit region (en-GB) is honored.
	 */
	public function resolveLocale(string $raw): ?string
	{
		$raw = trim($raw);
		if ($raw === '' || strlen($raw) > self::MAX_LOCALE_LENGTH || preg_match(self::LOCALE_PATTERN, $raw) !== 1) {
			return null;
		}

		$locale = LocaleTwigAdapter::canonicalizeLocale($raw);
		if ($locale === '' || !$this->hasLocale($locale)) {
			return null;
		}

		$active = $this->translator->getLocale();
		if (!str_contains($locale, '_') && strtok($active, '_') === $locale) {
			return $active;
		}

		return $locale;
	}

	public function getLocale(): string
	{
		return $this->translator->getLocale();
	}

	public function getTranslator(): Translator
	{
		return $this->translator;
	}

	private function loadTranslations(string $locale): void
	{
		// Adding a resource makes Symfony drop its loaded catalogs, so each
		// locale's files go in once, however often the locale is switched.
		if (isset($this->loadedLocales[$locale])) {
			return;
		}
		$this->loadedLocales[$locale] = true;

		$domains = ['admin', 'js'];

		foreach ($domains as $domain) {
			// Always load English as the fallback
			$enFile = $this->translationsPath . "/{$domain}.en_US.php";
			if (!$this->englishLoaded && file_exists($enFile)) {
				$this->translator->addResource('php', $enFile, 'en_US', $domain);
			}

			// Load the configured locale if different from English. A bare
			// language code (es) or an unshipped region (es_AR) falls down to
			// the first matching region file (es_ES) — mirrors the region
			// fall-down the content-locale Twig helper does. Messages are
			// registered under the requested code so the active locale resolves.
			if ($locale !== 'en_US') {
				$localeFile = $this->resolveTranslationFile($domain, $locale);
				if ($localeFile !== null) {
					$this->translator->addResource('php', $localeFile, $locale, $domain);
				}
			}
		}

		$this->englishLoaded = true;
	}

	/**
	 * Resolve the translation file for a domain + locale, applying region
	 * fall-down. Tries the exact `{domain}.{locale}.php` first, then the first
	 * `{domain}.{language}_*.php` variant (e.g. es → es_ES). Returns null when
	 * no file is shipped for the language.
	 */
	private function resolveTranslationFile(string $domain, string $locale): ?string
	{
		$exact = $this->translationsPath . "/{$domain}.{$locale}.php";
		if (file_exists($exact)) {
			return $exact;
		}

		$language = strtok($locale, '_');
		if ($language === false) {
			return null;
		}

		$matches = glob($this->translationsPath . "/{$domain}.{$language}_*.php");
		if ($matches === false || $matches === []) {
			return null;
		}

		sort($matches);

		return $matches[0];
	}
}
