<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\Install\ExtensionArchive;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;

function archive(): ExtensionArchive
{
	return new ExtensionArchive(new ManifestValidator(test()->createMock(EditionFeatureService::class)));
}

describe('ExtensionArchive::inspect', function (): void {
	test('accepts extension.json at the zip root', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing'),
			'Extension.php'  => testExtensionClass(),
		]);

		$plan = archive()->inspect($zip);

		expect($plan->manifest->id)->toBe('acme/thing')
			->and($plan->prefix)->toBe('')
			->and($plan->fileCount)->toBe(2)
			->and($plan->isUpdate())->toBeFalse();
	});

	test('accepts a single wrapper folder and reports it as the prefix', function (): void {
		$zip = buildTestZip([
			'thing/'               => null,
			'thing/extension.json' => testExtensionManifest('acme/thing'),
			'thing/Extension.php'  => testExtensionClass(),
			'thing/src/'           => null,
			'thing/src/Foo.php'    => '<?php',
		]);

		$plan = archive()->inspect($zip);

		expect($plan->prefix)->toBe('thing/')
			->and($plan->fileCount)->toBe(3);
	});

	test('ignores __MACOSX and .DS_Store entries', function (): void {
		$zip = buildTestZip([
			'thing/extension.json'            => testExtensionManifest('acme/thing'),
			'thing/Extension.php'             => testExtensionClass(),
			'thing/.DS_Store'                 => 'junk',
			'__MACOSX/thing/._extension.json' => 'junk',
			'__MACOSX/._thing'                => 'junk',
		]);

		$plan = archive()->inspect($zip);

		expect($plan->prefix)->toBe('thing/')
			->and($plan->fileCount)->toBe(2);
	});

	test('rejects a zip with no manifest', function (): void {
		$zip = buildTestZip(['thing/Extension.php' => testExtensionClass()]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'extension.json');
	});

	test('rejects a zip with two candidate manifests', function (): void {
		$zip = buildTestZip([
			'a/extension.json' => testExtensionManifest('acme/a'),
			'b/extension.json' => testExtensionManifest('acme/b'),
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'more than one');
	});

	test('rejects a wrapper folder with stray files beside it', function (): void {
		$zip = buildTestZip([
			'thing/extension.json' => testExtensionManifest('acme/thing'),
			'README.md'            => 'stray',
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'single folder');
	});

	test('rejects a path traversal entry', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing'),
			'../evil.php'    => '<?php',
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'unsafe path');
	});

	test('rejects an absolute path entry', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing'),
			'/etc/passwd'    => 'x',
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'unsafe path');
	});

	test('rejects a backslash path entry', function (): void {
		$zip = buildTestZip([
			'extension.json'    => testExtensionManifest('acme/thing'),
			'src\\..\\evil.php' => 'x',
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'unsafe path');
	});

	test('rejects a symlink entry', function (): void {
		$dir = tcmsTestTempDir('tcms-ext-symlink');
		mkdir($dir, 0755, true);
		$path = $dir . '/archive.zip';
		$zip  = new ZipArchive();
		$zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$zip->addFromString('extension.json', testExtensionManifest('acme/thing'));
		$zip->addFromString('link', '/etc/passwd');
		// S_IFLNK | 0777, shifted into the high 16 bits where libzip keeps unix mode.
		$zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
		$zip->close();

		expect(fn () => archive()->inspect($path))
			->toThrow(InstallException::class, 'symlink');
	});

	test('rejects a file that is not a zip', function (): void {
		$dir = tcmsTestTempDir('tcms-ext-notzip');
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/archive.zip', 'this is not a zip');

		expect(fn () => archive()->inspect($dir . '/archive.zip'))
			->toThrow(InstallException::class, 'not a zip');
	});

	test('rejects an invalid manifest id', function (): void {
		$zip = buildTestZip(['extension.json' => testExtensionManifest('Not Valid')]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'Invalid extension ID');
	});

	test('rejects an incompatible extension', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing', '1.0.0', ['requires' => ['php' => '>=99.0']]),
		]);

		expect(fn () => archive()->inspect($zip))
			->toThrow(InstallException::class, 'Requires PHP');
	});

	test('warns when composer.json has requires but vendor/autoload.php is missing', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing'),
			'composer.json'  => json_encode(['require' => ['php' => '>=8.2', 'guzzlehttp/guzzle' => '^7']]),
		]);

		$plan = archive()->inspect($zip);

		expect($plan->warnings)->toHaveCount(1)
			->and($plan->warnings[0])->toContain('vendor/autoload.php');
	});

	test('does not warn when composer.json only requires php and ext-*', function (): void {
		$zip = buildTestZip([
			'extension.json' => testExtensionManifest('acme/thing'),
			'composer.json'  => json_encode(['require' => ['php' => '>=8.2', 'ext-zip' => '*']]),
		]);

		expect(archive()->inspect($zip)->warnings)->toBe([]);
	});
});

describe('ExtensionArchive::extractTo', function (): void {
	test('extracts under the destination with the prefix stripped and ignored entries skipped', function (): void {
		$zip = buildTestZip([
			'thing/extension.json' => testExtensionManifest('acme/thing'),
			'thing/Extension.php'  => testExtensionClass(),
			'thing/src/Foo.php'    => '<?php // foo',
			'thing/.DS_Store'      => 'junk',
			'__MACOSX/._thing'     => 'junk',
		]);
		$dest = tcmsTestTempDir('tcms-ext-extract');

		$archive = archive();
		$archive->extractTo($zip, $archive->inspect($zip), $dest);

		expect(is_file($dest . '/extension.json'))->toBeTrue()
			->and(file_get_contents($dest . '/src/Foo.php'))->toBe('<?php // foo')
			->and(file_exists($dest . '/.DS_Store'))->toBeFalse()
			->and(is_dir($dest . '/__MACOSX'))->toBeFalse()
			->and(is_dir($dest . '/thing'))->toBeFalse();

		recursiveDelete($dest);
	});
});

describe('ExtensionArchive::extractTo hardening', function (): void {
	test('refuses a plan whose prefix does not match the zip', function (): void {
		$zipA = buildTestZip(['thing/extension.json' => testExtensionManifest('acme/thing')]);
		$zipB = buildTestZip(['other/extension.json' => testExtensionManifest('acme/other')]);
		$dest = tcmsTestTempDir('tcms-ext-extract');

		$archive = archive();
		$plan    = $archive->inspect($zipA);

		expect(fn () => $archive->extractTo($zipB, $plan, $dest))->toThrow(InstallException::class);

		recursiveDelete($dest);
	});

	test('a wrongly typed manifest field is an InstallException, not a PHP error', function (): void {
		$zip = buildTestZip(['extension.json' => json_encode(['id' => [], 'name' => 'x', 'version' => '1.0.0'])]);

		expect(fn () => archive()->inspect($zip))->toThrow(InstallException::class);
	});
});
