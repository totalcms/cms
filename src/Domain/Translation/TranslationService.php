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
	 * @param array<string,string> $parameters
	 */
	public function trans(string $key, array $parameters = [], string $domain = 'admin'): string
	{
		return $this->translator->trans($key, $parameters, $domain);
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

			// Load the configured locale if different from English
			if ($locale !== 'en_US') {
				$localeFile = $this->translationsPath . "/{$domain}.{$locale}.php";
				if (file_exists($localeFile)) {
					$this->translator->addResource('php', $localeFile, $locale, $domain);
				}
			}
		}
	}
}
