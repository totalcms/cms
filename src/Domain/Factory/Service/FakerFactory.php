<?php

namespace TotalCMS\Domain\Factory\Service;

use Faker\Factory;
use Faker\Generator;
use TotalCMS\Domain\Factory\Faker\FakerExtension;
use TotalCMS\Support\Config;

/**
 * Factory.
 */
class FakerFactory
{
	public string $cacheDir;

	public function __construct(
		private Config $config,
	) {
		$this->config   = $config;
		$this->cacheDir = $this->config->tmpdir . '/faker-images';
	}

	public function createFaker(): Generator
	{
		$faker = $this->safeFactory($this->config->locale);
		$faker->addProvider(new FakerExtension($faker));

		if (!is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0700, true);
		}

		FakerExtension::$dir = $this->cacheDir;

		return $faker;
	}

	/**
	 * Build a Faker generator for $locale, always falling back to en_US.
	 *
	 * Faker resolves provider classes per locale and throws when it can't.
	 * A falsy locale ('' or '0' — both falsy in PHP) makes it resolve the
	 * abstract base Text provider ("Cannot instantiate abstract class
	 * Faker\Provider\Text"), and a distribution bundle that trims locale
	 * provider directories can leave a configured locale unsatisfiable.
	 * Either failure is fatal and unhandled: FactoryImporter builds Faker
	 * in its constructor and JobRunner depends on FactoryImporter, so one
	 * bad locale takes down the whole `jobs:process` run (dataview
	 * rebuilds, imports, RSS, search reindex, scheduled automations) — the
	 * same blast radius as the ReindexJob unbound-logger crash. Normalize
	 * to en_US so a bad locale can never crash job processing.
	 */
	private function safeFactory(string $locale): Generator
	{
		$locale = trim($locale);

		if ($locale !== '' && $locale !== Factory::DEFAULT_LOCALE) {
			try {
				return Factory::create($locale);
			} catch (\Throwable) {
				// Fall through to the guaranteed-present default locale.
			}
		}

		return Factory::create(Factory::DEFAULT_LOCALE);
	}

	public function createFallbackFaker(): Generator
	{
		$faker = Factory::create('en_US');
		$faker->addProvider(new FakerExtension($faker));
		FakerExtension::$dir = $this->cacheDir;

		return $faker;
	}
}
