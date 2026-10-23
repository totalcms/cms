<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Action\Extension\ExtensionInstallAction;
use TotalCMS\Domain\Extension\Service\Install\ExtensionInstaller;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Domain\Extension\Service\Install\InstallResult;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

function installActionWith(ExtensionInstaller $installer): ExtensionInstallAction
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->tmpdir = tcmsTestTempDir('tcms-ext-action');
	mkdir($config->tmpdir, 0755, true);

	$routes = test()->createMock(RouteParserInterface::class);
	$routes->method('urlFor')->willReturnCallback(
		static fn (string $name, array $data = [], array $query = []): string => ($name === 'admin-extension-review'
			? '/admin/extensions/' . ($data['extension'] ?? '') . '/review'
			: '/admin/extensions') . ($query !== [] ? '?' . http_build_query($query) : ''),
	);

	return new ExtensionInstallAction($installer, new JsonRenderer(), $config, $routes);
}

/** @return array{status: int, body: array<string,mixed>} */
function installInvoke(ExtensionInstallAction $action, ?UploadedFile $file): array
{
	$factory = new Psr17Factory();
	$request = $factory->createServerRequest('POST', '/api/extensions/install');
	if ($file !== null) {
		$request = $request->withUploadedFiles(['extension' => $file]);
	}
	$response = $action($request, $factory->createResponse(), []);

	return ['status' => $response->getStatusCode(), 'body' => (array)json_decode((string)$response->getBody(), true)];
}

function uploadedZip(string $contents = 'zip bytes'): UploadedFile
{
	$path = tcmsTestTempDir('tcms-ext-upload');
	mkdir($path, 0755, true);
	file_put_contents($path . '/thing.zip', $contents);

	return new UploadedFile($path . '/thing.zip', strlen($contents), UPLOAD_ERR_OK, 'thing.zip', 'application/zip');
}

function fakeResult(bool $isUpdate): InstallResult
{
	return new InstallResult('acme/thing', 'Thing', '1.1.0', $isUpdate ? '1.0.0' : null, $isUpdate, 0, false, [], '/data/extensions/acme/thing');
}

describe('ExtensionInstallAction', function (): void {
	test('a fresh install redirects to the review page, marked as just installed', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->expects(test()->once())->method('install')->willReturn(fakeResult(false));

		$out = installInvoke(installActionWith($installer), uploadedZip());

		expect($out['status'])->toBe(200)
			->and($out['body']['status'])->toBe('installed')
			->and($out['body']['redirect'])->toBe('/admin/extensions/acme/thing/review?installed=1');
	});

	test('an update redirects to the extensions page', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->method('install')->willReturn(fakeResult(true));

		$out = installInvoke(installActionWith($installer), uploadedZip());

		expect($out['body']['status'])->toBe('updated')
			->and($out['body']['redirect'])->toBe('/admin/extensions');
	});

	test('a missing file is a 400', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->expects(test()->never())->method('install');

		$out = installInvoke(installActionWith($installer), null);

		expect($out['status'])->toBe(400)
			->and($out['body']['error'])->toContain('No file');
	});

	test('an upload over the PHP size limit names the limit', function (int $code): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->expects(test()->never())->method('install');
		$file = new UploadedFile('', 0, $code, 'thing.zip', 'application/zip');

		$out = installInvoke(installActionWith($installer), $file);

		expect($out['status'])->toBe(400)
			->and($out['body']['code'])->toBe('too_large')
			->and($out['body']['error'])->toContain("exceeds this server's upload limit")
			->and($out['body']['error'])->not->toContain('No file');
	})->with([
		'ini size'  => [UPLOAD_ERR_INI_SIZE],
		'form size' => [UPLOAD_ERR_FORM_SIZE],
	]);

	test('an empty file input says no file was uploaded', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->expects(test()->never())->method('install');

		$out = installInvoke(installActionWith($installer), new UploadedFile('', 0, UPLOAD_ERR_NO_FILE));

		expect($out['status'])->toBe(400)
			->and($out['body']['code'])->toBe('no_file')
			->and($out['body']['error'])->toContain('No file was uploaded');
	});

	test('any other upload error is a generic upload failure', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->expects(test()->never())->method('install');

		$out = installInvoke(installActionWith($installer), new UploadedFile('', 0, UPLOAD_ERR_PARTIAL));

		expect($out['status'])->toBe(400)
			->and($out['body']['code'])->toBe('upload_failed')
			->and($out['body']['error'])->toContain('upload failed');
	});

	test('a bad zip is a 400 with a code', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->method('install')->willThrowException(InstallException::invalidArchive('The uploaded file is not a zip archive, or it is damaged.'));

		$out = installInvoke(installActionWith($installer), uploadedZip('not a zip'));

		expect($out['status'])->toBe(400)
			->and($out['body']['code'])->toBe('invalid_archive')
			->and($out['body']['error'])->toContain('not a zip');
	});

	test('the temporary upload is deleted afterwards, success or failure', function (): void {
		$installer = test()->createMock(ExtensionInstaller::class);
		$installer->method('install')->willThrowException(InstallException::invalidArchive('bad'));
		$action = installActionWith($installer);

		installInvoke($action, uploadedZip());

		$config = (new ReflectionProperty(ExtensionInstallAction::class, 'config'))->getValue($action);
		expect(glob($config->tmpdir . '/extension-install/*.zip') ?: [])->toBe([]);
	});
});
