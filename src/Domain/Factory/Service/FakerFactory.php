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
		$faker = Factory::create($this->config->locale);
		$faker->addProvider(new FakerExtension($faker));

		if (!is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0700, true);
		}

		FakerExtension::$dir = $this->cacheDir;

		return $faker;
	}

	public function createFallbackFaker(): Generator
	{
		$faker = Factory::create('en_US');
		$faker->addProvider(new FakerExtension($faker));
		FakerExtension::$dir = $this->cacheDir;

		return $faker;
	}
}
