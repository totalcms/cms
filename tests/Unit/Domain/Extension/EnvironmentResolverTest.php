<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Support\Config;

function configWithEnv(string $env): Config
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	return $config;
}

describe('EnvironmentResolver', function () {
	test('quarantine allowed only in prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeTrue();
	});

	test('quarantine blocked in dev', function () {
		$resolver = new EnvironmentResolver(configWithEnv('dev'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('quarantine blocked when preview, even if env is prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: true);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('unknown env is treated as non-prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('staging'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('errors surface loudly when not prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('dev'), isPreview: false);
		expect($resolver->shouldSurfaceErrors())->toBeTrue();
	});

	test('errors do not surface loudly in prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: false);
		expect($resolver->shouldSurfaceErrors())->toBeFalse();
	});
});
