<?php

namespace TotalCMS\Factory;

use Faker\Factory;
use Faker\Generator;
use TotalCMS\Support\Config;
use TotalCMS\Utils\Faker\FakerExtension;

/**
 * Factory.
 */
final class FakerFactory
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
			mkdir($this->cacheDir, 0777, true);
		}

		FakerExtension::$dir = $this->cacheDir;

		return $faker;
	}
}
