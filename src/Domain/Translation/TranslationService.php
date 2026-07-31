<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Translation;

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use TotalCMS\Support\Config;

/**
 * Translation service wrapping Symfony Translator.
 *
 * Loads PHP array files from resources/translations/ for OPcache-friendly performance.
 */
class TranslationService
{
	private readonly Translator $translator;

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
		$domains = ['admin', 'js'];

		foreach ($domains as $domain) {
			// Always load English as the fallback
			$enFile = $this->translationsPath . "/{$domain}.en_US.php";
			if (file_exists($enFile)) {
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
