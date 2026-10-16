<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\Install\ExtensionArchive;
use TotalCMS\Domain\Extension\Service\Install\ExtensionPackager;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;

function packagerFixture(array $extraFiles = [], string $version = '1.2.0'): string
{
	$dir = tcmsTestTempDir('tcms-ext-pack');
	mkdir($dir . '/thing', 0755, true);
	file_put_contents($dir . '/thing/extension.json', testExtensionManifest('acme/thing', $version));
	file_put_contents($dir . '/thing/Extension.php', testExtensionClass());
	foreach ($extraFiles as $rel => $contents) {
		@mkdir(dirname($dir . '/thing/' . $rel), 0755, true);
		file_put_contents($dir . '/thing/' . $rel, $contents);
	}

	return $dir . '/thing';
}

function packager(): ExtensionPackager
{
	return new ExtensionPackager(new ManifestValidator(test()->createMock(EditionFeatureService::class)));
}

function zipNames(string $zipPath): array
{
	$zip = new ZipArchive();
	$zip->open($zipPath);
	$names = [];
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$names[] = $zip->getNameIndex($i);
	}
	$zip->close();
	sort($names);

	return $names;
}

afterEach(function (): void {
	foreach (glob(sys_get_temp_dir() . '/tcms-ext-pack-*') ?: [] as $dir) {
		recursiveDelete($dir);
	}
});

describe('ExtensionPackager', function (): void {
	test('writes {name}-{version}.zip with a single {name}/ wrapper', function (): void {
		$dir = packagerFixture(['src/Foo.php' => '<?php']);
		$out = tcmsTestTempDir('tcms-ext-pack-out');
		mkdir($out, 0755, true);

		$result = packager()->package($dir, $out);

		expect($result->zipPath)->toBe($out . '/thing-1.2.0.zip')
			->and($result->fileCount)->toBe(3)
			->and(zipNames($result->zipPath))->toContain('thing/extension.json', 'thing/Extension.php', 'thing/src/Foo.php');
	});

	test('excludes .git, .github, .DS_Store, node_modules and tests but keeps vendor', function (): void {
		$dir = packagerFixture([
			'.git/HEAD'                  => 'ref',
			'.github/workflows/ci.yml'   => 'x',
			'.DS_Store'                  => 'junk',
			'node_modules/a/index.js'    => 'x',
			'tests/FooTest.php'          => 'x',
			'vendor/autoload.php'        => '<?php',
			'src/.DS_Store'              => 'junk',
		]);

		$names = zipNames(packager()->package($dir, dirname($dir))->zipPath);

		expect($names)->toContain('thing/vendor/autoload.php')
			->and(implode("\n", $names))->not->toContain('.git/')
			->and(implode("\n", $names))->not->toContain('.github/')
			->and(implode("\n", $names))->not->toContain('.DS_Store')
			->and(implode("\n", $names))->not->toContain('node_modules/')
			->and(implode("\n", $names))->not->toContain('tests/');
	});

	test('defaults the output directory to the extension parent and refuses to overwrite', function (): void {
		$dir = packagerFixture();

		$first = packager()->package($dir);

		expect($first->zipPath)->toBe(dirname($dir) . '/thing-1.2.0.zip');
		expect(fn () => packager()->package($dir))
			->toThrow(InstallException::class, 'already exists');
		expect(packager()->package($dir, null, true)->zipPath)->toBe($first->zipPath);
	});

	test('warns when composer.json has requires but no vendor/autoload.php', function (): void {
		$dir = packagerFixture(['composer.json' => json_encode(['require' => ['monolog/monolog' => '^3']])]);

		expect(packager()->package($dir)->warnings)->toHaveCount(1);
	});

	test('rejects a directory without a valid manifest', function (): void {
		$dir = tcmsTestTempDir('tcms-ext-pack-bad');
		mkdir($dir, 0755, true);

		expect(fn () => packager()->package($dir))
			->toThrow(InstallException::class, 'extension.json');
	});

	test('leaves an existing zip untouched and no temp file when writing fails', function (): void {
		$dir = packagerFixture(['secret.php' => '<?php']);
		$out = dirname($dir);
		file_put_contents($out . '/thing-1.2.0.zip', 'GOOD');
		chmod($dir . '/secret.php', 0000);

		try {
			expect(fn () => packager()->package($dir, null, true))->toThrow(InstallException::class);
		} finally {
			chmod($dir . '/secret.php', 0644);
		}

		expect(file_get_contents($out . '/thing-1.2.0.zip'))->toBe('GOOD')
			->and(glob($out . '/*.tmp-*'))->toBe([]);
	})->skip(posix_geteuid() === 0, 'root can read any file');

	test('round-trips through ExtensionArchive::inspect', function (): void {
		$dir       = packagerFixture(['src/Foo.php' => '<?php']);
		$validator = new ManifestValidator(test()->createMock(EditionFeatureService::class));

		$plan = (new ExtensionArchive($validator))->inspect(packager()->package($dir)->zipPath);

		expect($plan->manifest->id)->toBe('acme/thing')
			->and($plan->prefix)->toBe('thing/')
			->and($plan->fileCount)->toBe(3)
			->and($plan->warnings)->toBe([]);
	});
});
