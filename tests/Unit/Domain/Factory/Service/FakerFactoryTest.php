<?php

declare(strict_types=1);

use Faker\Generator;
use TotalCMS\Domain\Factory\Service\FakerFactory;
use TotalCMS\Support\Config;

/**
 * A falsy locale ('' or '0' — both falsy in PHP) made Faker resolve the
 * abstract base Text provider ("Cannot instantiate abstract class
 * Faker\Provider\Text"). Unhandled, and because FactoryImporter builds Faker
 * in its constructor and JobRunner depends on FactoryImporter, it took the
 * whole `jobs:process` run down. createFaker() must normalize any bad locale
 * to en_US instead of crashing.
 */
function fakerFactoryWithLocale(string $locale): FakerFactory
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->locale = $locale;
	$config->tmpdir = sys_get_temp_dir() . '/tcms-faker-test-' . uniqid();

	return new FakerFactory($config);
}

test('createFaker survives a falsy or unsatisfiable locale', function (string $locale): void {
	$faker = fakerFactoryWithLocale($locale)->createFaker();

	expect($faker)->toBeInstanceOf(Generator::class)
		->and($faker->name())->toBeString()->not->toBe('');
})->with([
	'empty string'      => [''],
	'falsy zero string' => ['0'],
	'whitespace only'   => ['   '],
	'unknown locale'    => ['xx_XX'],
	'valid non-default' => ['fr_FR'],
	'default locale'    => ['en_US'],
]);
