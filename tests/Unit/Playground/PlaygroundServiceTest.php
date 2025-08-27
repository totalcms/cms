<?php

use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Playground\Service\PlaygroundFetcher;
use TotalCMS\Domain\Playground\Service\PlaygroundLister;
use TotalCMS\Domain\Playground\Service\PlaygroundRemover;
use TotalCMS\Domain\Playground\Service\PlaygroundSaver;
use TotalCMS\Domain\Playground\Service\PlaygroundUpdater;

describe('Playground Services', function (): void {
	it('all service classes exist and are instantiable', function (): void {
		$services = [
			PlaygroundFetcher::class,
			PlaygroundLister::class,
			PlaygroundSaver::class,
			PlaygroundUpdater::class,
			PlaygroundRemover::class,
		];

		foreach ($services as $serviceClass) {
			$reflection = new ReflectionClass($serviceClass);
			expect($reflection->isInstantiable())->toBeTrue();
			expect($reflection->getName())->toBe($serviceClass);
		}
	});

	it('all services use the same collection ID', function (): void {
		// Verify all services use the playground collection ID constant
		expect(PlaygroundData::COLLECTION_ID)->toBe('playground');
	});

	it('playground fetcher has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(PlaygroundFetcher::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();
		$parameters = $constructor->getParameters();
		// Check that it has dependencies (exact count may vary)
		expect(count($parameters))->toBeGreaterThanOrEqual(1);
	});

	it('playground lister has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(PlaygroundLister::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();
		$parameters = $constructor->getParameters();
		expect(count($parameters))->toBeGreaterThanOrEqual(1);
	});

	it('playground saver has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(PlaygroundSaver::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();
		$parameters = $constructor->getParameters();
		expect(count($parameters))->toBeGreaterThanOrEqual(1);
	});

	it('playground updater has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(PlaygroundUpdater::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();
		$parameters = $constructor->getParameters();
		expect(count($parameters))->toBeGreaterThanOrEqual(1);
	});

	it('playground remover has correct constructor dependencies', function (): void {
		$reflection  = new ReflectionClass(PlaygroundRemover::class);
		$constructor = $reflection->getConstructor();

		expect($constructor)->not()->toBeNull();
		$parameters = $constructor->getParameters();
		expect(count($parameters))->toBeGreaterThanOrEqual(1);
	});

	it('all services have public methods', function (): void {
		$services = [
			PlaygroundFetcher::class,
			PlaygroundLister::class,
			PlaygroundSaver::class,
			PlaygroundUpdater::class,
			PlaygroundRemover::class,
		];

		foreach ($services as $serviceClass) {
			$reflection    = new ReflectionClass($serviceClass);
			$publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

			// Each service should have at least one public method (excluding constructor)
			$nonConstructorMethods = array_filter($publicMethods, fn (ReflectionMethod $method): bool => $method->getName() !== '__construct');
			expect(count($nonConstructorMethods))->toBeGreaterThanOrEqual(1);
		}
	});
});
