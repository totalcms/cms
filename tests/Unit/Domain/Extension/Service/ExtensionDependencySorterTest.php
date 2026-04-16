<?php

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ExtensionDependencySorter;

describe('ExtensionDependencySorter', function (): void {
	test('sorts independent extensions in discovery order', function (): void {
		$sorter    = new ExtensionDependencySorter();
		$manifests = [
			'vendor/a' => ExtensionManifest::fromArray(['id' => 'vendor/a', 'name' => 'A', 'version' => '1.0.0']),
			'vendor/b' => ExtensionManifest::fromArray(['id' => 'vendor/b', 'name' => 'B', 'version' => '1.0.0']),
		];

		$sorted = $sorter->sort($manifests);

		expect($sorted)->toContain('vendor/a');
		expect($sorted)->toContain('vendor/b');
		expect($sorted)->toHaveCount(2);
	});

	test('sorts dependencies before dependents', function (): void {
		$sorter    = new ExtensionDependencySorter();
		$manifests = [
			'vendor/child' => ExtensionManifest::fromArray([
				'id'       => 'vendor/child',
				'name'     => 'Child',
				'version'  => '1.0.0',
				'requires' => ['extensions' => ['vendor/parent' => '>=1.0.0']],
			]),
			'vendor/parent' => ExtensionManifest::fromArray([
				'id'      => 'vendor/parent',
				'name'    => 'Parent',
				'version' => '1.0.0',
			]),
		];

		$sorted = $sorter->sort($manifests);

		$parentIndex = array_search('vendor/parent', $sorted, true);
		$childIndex  = array_search('vendor/child', $sorted, true);

		expect($parentIndex)->toBeLessThan($childIndex);
	});

	test('handles diamond dependencies', function (): void {
		$sorter    = new ExtensionDependencySorter();
		$manifests = [
			'vendor/d' => ExtensionManifest::fromArray([
				'id'       => 'vendor/d', 'name' => 'D', 'version' => '1.0.0',
				'requires' => ['extensions' => ['vendor/b' => '>=1.0.0', 'vendor/c' => '>=1.0.0']],
			]),
			'vendor/b' => ExtensionManifest::fromArray([
				'id'       => 'vendor/b', 'name' => 'B', 'version' => '1.0.0',
				'requires' => ['extensions' => ['vendor/a' => '>=1.0.0']],
			]),
			'vendor/c' => ExtensionManifest::fromArray([
				'id'       => 'vendor/c', 'name' => 'C', 'version' => '1.0.0',
				'requires' => ['extensions' => ['vendor/a' => '>=1.0.0']],
			]),
			'vendor/a' => ExtensionManifest::fromArray([
				'id' => 'vendor/a', 'name' => 'A', 'version' => '1.0.0',
			]),
		];

		$sorted = $sorter->sort($manifests);

		$aIndex = array_search('vendor/a', $sorted, true);
		$bIndex = array_search('vendor/b', $sorted, true);
		$cIndex = array_search('vendor/c', $sorted, true);
		$dIndex = array_search('vendor/d', $sorted, true);

		expect($aIndex)->toBeLessThan($bIndex);
		expect($aIndex)->toBeLessThan($cIndex);
		expect($bIndex)->toBeLessThan($dIndex);
		expect($cIndex)->toBeLessThan($dIndex);
	});

	test('detects circular dependencies', function (): void {
		$sorter    = new ExtensionDependencySorter();
		$manifests = [
			'vendor/a' => ExtensionManifest::fromArray([
				'id'       => 'vendor/a', 'name' => 'A', 'version' => '1.0.0',
				'requires' => ['extensions' => ['vendor/b' => '>=1.0.0']],
			]),
			'vendor/b' => ExtensionManifest::fromArray([
				'id'       => 'vendor/b', 'name' => 'B', 'version' => '1.0.0',
				'requires' => ['extensions' => ['vendor/a' => '>=1.0.0']],
			]),
		];

		expect(fn (): array => $sorter->sort($manifests))->toThrow(RuntimeException::class, 'Circular dependency');
	});

	test('handles empty input', function (): void {
		$sorter = new ExtensionDependencySorter();

		expect($sorter->sort([]))->toBe([]);
	});
});
