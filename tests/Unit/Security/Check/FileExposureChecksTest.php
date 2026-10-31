<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Check\DataFolderPublicCheck;
use TotalCMS\Domain\Security\Check\DirectoryListingCheck;
use TotalCMS\Domain\Security\Check\SensitiveFilesPublicCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/** Temp install: <tmp>/public (docroot) with tcms-data inside it; returns [tmp, config]. */
function exposureInstall(bool $dataInsideDocroot = true): array
{
	$tmp = sys_get_temp_dir() . '/tcms-expo-' . bin2hex(random_bytes(4));
	mkdir($tmp . '/public', 0777, true);
	$data = $dataInsideDocroot ? $tmp . '/public/tcms-data' : $tmp . '/tcms-data';
	mkdir($data . '/.system', 0777, true);
	$tmp = realpath($tmp);
	$data = $dataInsideDocroot ? $tmp . '/public/tcms-data' : $tmp . '/tcms-data';

	$config                 = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->docroot        = $tmp . '/public';
	$config->root           = $tmp;
	$config->datadir        = $data;
	$config->cachedir       = $tmp . '/cache';
	$config->tmpdir         = $tmp . '/tmp';
	$config->logger         = ['path' => $data . '/.system/logs'];
	$config->domain         = 'example.com';
	$config->url            = 'https://example.com';

	return [$tmp, $config];
}

function exposureCleanup(string $tmp): void
{
	exec('/bin/rm -rf ' . escapeshellarg($tmp));
}

function exposureContext(string $base = 'https://example.com', string $software = 'Apache/2.4'): SecurityContext
{
	return new SecurityContext($base, 'example.com', true, true, $software);
}

/** $handler(string $url, string $docroot): ?HttpResponse (null throws). */
function exposureClient(callable $handler, string $docroot): object
{
	return new class($handler, $docroot) implements HttpClientInterface {
		public int $calls = 0;

		public function __construct(private $handler, private string $docroot)
		{
		}

		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$this->calls++;
			$response = ($this->handler)($url, $this->docroot);
			if ($response === null) {
				throw new RuntimeException('connection refused');
			}

			return $response;
		}
	};
}

function exposureServeFromDisk(): callable
{
	return static function (string $url, string $docroot): HttpResponse {
		$path = $docroot . rawurldecode((string)parse_url($url, PHP_URL_PATH));
		if (is_file($path)) {
			return new HttpResponse(200, (string)file_get_contents($path));
		}

		return new HttpResponse(404, 'nope');
	};
}

function exposureProbe(object $client, Config $config): SiteProbe
{
	return new SiteProbe($client, $config);
}

function leftoverCanaries(string $tmp): array
{
	return glob($tmp . '/public/tcms-data/security-probe-*') ?: [];
}

describe('DataFolderPublicCheck', function (): void {
	test('data folder outside the docroot is ok and makes no request', function (): void {
		[$tmp, $config] = exposureInstall(false);
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings)->toHaveCount(1)
			->and($findings[0]->status)->toBe(FindingStatus::Ok)
			->and($client->calls)->toBe(0);
		exposureCleanup($tmp);
	});

	test('data folder served is critical', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Found)
			->and($findings[0]->severity)->toBe(Severity::Critical)
			->and($findings[0]->id)->toBe('data-folder-public')
			->and($findings[0]->detail)->not->toContain('security-probe-');
		expect(leftoverCanaries($tmp))->toBe([]);
		exposureCleanup($tmp);
	});

	test('catch-all 200 page is not exposure', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(static fn (): HttpResponse => new HttpResponse(200, '<html>Not found</html>'), $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Found)
			->and($findings[0]->severity)->toBe(Severity::Info);

		$nginx = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext('https://example.com', 'nginx/1.25'));
		expect($nginx[0]->severity)->toBe(Severity::Warning);
		exposureCleanup($tmp);
	});

	test('unreachable site is skipped, not ok', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(static fn (): ?HttpResponse => null, $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Skipped)
			->and($findings[0]->detail)->toBe(SiteProbe::UNREACHABLE);
		exposureCleanup($tmp);
	});

	test('inside the docroot with no base URL is skipped with NO_URL, not ok', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext(''));

		expect($findings[0]->status)->toBe(FindingStatus::Skipped)
			->and($findings[0]->detail)->toBe(SiteProbe::NO_URL)
			->and($client->calls)->toBe(0);
		exposureCleanup($tmp);
	});

	test('canary is removed even when the client throws', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(static fn (): ?HttpResponse => null, $config->docroot);
		(new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect(leftoverCanaries($tmp))->toBe([]);
		exposureCleanup($tmp);
	});

	test('a data folder reached through a symlink inside the docroot is probed and critical when served', function (): void {
		[$tmp, $config] = exposureInstall(false);
		symlink($tmp . '/tcms-data', $tmp . '/public/linked');
		$config->datadir = $tmp . '/public/linked';
		$client          = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings        = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Found)
			->and($findings[0]->severity)->toBe(Severity::Critical);
		expect(glob($tmp . '/tcms-data/security-probe-*'))->toBe([]);
		exposureCleanup($tmp);
	});

	test('a server that denies dot paths but serves the data folder is still critical', function (): void {
		[$tmp, $config] = exposureInstall();
		$serve          = exposureServeFromDisk();
		$client         = exposureClient(static function (string $url, string $docroot) use ($serve): HttpResponse {
			foreach (explode('/', (string)parse_url($url, PHP_URL_PATH)) as $segment) {
				if (str_starts_with($segment, '.')) {
					return new HttpResponse(403, 'denied');
				}
			}

			return $serve($url, $docroot);
		}, $config->docroot);
		$findings = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext('https://example.com', 'nginx/1.25'));

		expect($findings[0]->severity)->toBe(Severity::Critical);
		exposureCleanup($tmp);
	});

	test('a missing data folder is ok without claiming it is outside the docroot', function (): void {
		[$tmp, $config] = exposureInstall();
		exposureCleanup($tmp . '/public/tcms-data');
		$client   = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Ok)
			->and($findings[0]->title)->not->toContain('outside')
			->and($client->calls)->toBe(0);
		exposureCleanup($tmp);
	});

	test('fix text mentions nginx when the server is not Apache', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new DataFolderPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext('https://example.com', 'nginx/1.25'));

		expect(strtolower($findings[0]->fix))->toContain('nginx')->toContain('.htaccess files are ignored');
		exposureCleanup($tmp);
	});
});

describe('SensitiveFilesPublicCheck', function (): void {
	test('exposed .git/config is critical', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($tmp . '/public/.git', 0777, true);
		file_put_contents($tmp . '/public/.git/config', "[core]\n\trepositoryformatversion = 0\n");
		$config->root = $tmp . '/public';

		$exposed  = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings = (new SensitiveFilesPublicCheck($config, exposureProbe($exposed, $config)))->run(exposureContext());
		$git      = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, '/.git/config')));
		expect($git)->toHaveCount(1)
			->and($git[0]->severity)->toBe(Severity::Critical)
			->and($git[0]->id)->toBe('sensitive-files-public');

		exposureCleanup($tmp);
	});

	test('a catch-all HTML 200 page produces no exposure finding', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($tmp . '/public/.git', 0777, true);
		file_put_contents($tmp . '/public/.git/config', "[core]\n");
		file_put_contents($tmp . '/public/.env', "APP_KEY=secret\n");
		file_put_contents($tmp . '/public/composer.lock', '{"packages":[]}');
		$config->root = $tmp . '/public';
		$client       = exposureClient(static fn (): HttpResponse => new HttpResponse(200, "<!doctype html>\n<html>APP_KEY=x</html>"), $config->docroot);
		$findings     = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings)->toHaveCount(1)
			->and($findings[0]->status)->toBe(FindingStatus::Ok);
		exposureCleanup($tmp);
	});

	test('exposed .env is critical and never prints contents', function (): void {
		[$tmp, $config] = exposureInstall();
		file_put_contents($tmp . '/public/.env', "APP_KEY=supersecretvalue\n");
		$config->root = $tmp . '/public';
		$client       = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings     = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		$env = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, '/.env')));
		expect($env)->toHaveCount(1)
			->and($env[0]->severity)->toBe(Severity::Critical)
			->and(json_encode($env[0]->toArray()))->not->toContain('supersecretvalue');
		exposureCleanup($tmp);
	});

	test('composer.lock exposed is a warning', function (): void {
		[$tmp, $config] = exposureInstall();
		file_put_contents($tmp . '/public/composer.lock', '{"packages":[{"name":"a/b"}]}');
		$config->root = $tmp . '/public';
		$client       = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings     = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		$lock = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, '/composer.lock')));
		expect($lock)->toHaveCount(1)
			->and($lock[0]->severity)->toBe(Severity::Warning)
			->and($lock[0]->status)->toBe(FindingStatus::Found);
		exposureCleanup($tmp);
	});

	test('an exposed log folder is critical', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($config->logger['path'], 0777, true);
		$client   = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		$logs = array_values(array_filter($findings, static fn ($f): bool => str_contains($f->detail, '/logs')));
		expect($logs)->toHaveCount(1)->and($logs[0]->severity)->toBe(Severity::Critical);
		expect(glob($config->logger['path'] . '/security-probe-*'))->toBe([]);
		exposureCleanup($tmp);
	});

	test('nothing inside the docroot is ok and makes no request', function (): void {
		[$tmp, $config] = exposureInstall(false);
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings)->toHaveCount(1)->and($findings[0]->status)->toBe(FindingStatus::Ok)
			->and($client->calls)->toBe(0);
		exposureCleanup($tmp);
	});

	test('unreachable site is skipped', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($config->logger['path'], 0777, true);
		$client         = exposureClient(static fn (): ?HttpResponse => null, $config->docroot);
		$findings       = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings)->toHaveCount(1)->and($findings[0]->status)->toBe(FindingStatus::Skipped);
		exposureCleanup($tmp);
	});

	test('inside the docroot with no base URL is skipped with NO_URL', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($config->logger['path'], 0777, true);
		$client         = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings       = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext(''));

		expect($findings)->toHaveCount(1)->and($findings[0]->status)->toBe(FindingStatus::Skipped)
			->and($findings[0]->detail)->toBe(SiteProbe::NO_URL);
		exposureCleanup($tmp);
	});
});

describe('SensitiveFilesPublicCheck extras', function (): void {
	test('an unwritable folder inside the docroot is skipped, not ok', function (): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			expect(true)->toBeTrue();
			return;
		}
		[$tmp, $config] = exposureInstall();
		mkdir($config->logger['path'], 0555, true);
		$client   = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());
		chmod($config->logger['path'], 0755);

		expect($findings)->toHaveCount(1)
			->and($findings[0]->status)->toBe(FindingStatus::Skipped)
			->and($findings[0]->detail)->toContain('write a test file');
		exposureCleanup($tmp);
	});

	test('an .env with a digit in the key name is detected', function (): void {
		[$tmp, $config] = exposureInstall();
		file_put_contents($tmp . '/public/.env', "DB2_HOST=localhost\n");
		$config->root = $tmp . '/public';
		$client       = exposureClient(exposureServeFromDisk(), $config->docroot);
		$findings     = (new SensitiveFilesPublicCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Found);
		exposureCleanup($tmp);
	});
});

describe('DirectoryListingCheck', function (): void {
	test('directory listing is a warning', function (): void {
		[$tmp, $config] = exposureInstall();
		mkdir($tmp . '/public/assets', 0777, true);
		$client   = exposureClient(static fn (): HttpResponse => new HttpResponse(200, '<html><head><title>Index of /assets</title>'), $config->docroot);
		$findings = (new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext());

		expect($findings[0]->status)->toBe(FindingStatus::Found)
			->and($findings[0]->severity)->toBe(Severity::Warning)
			->and($findings[0]->id)->toBe('directory-listing');
		exposureCleanup($tmp);
	});

	test('uses the configured assetsPath and says so when the folder is missing', function (): void {
		[$tmp, $config] = exposureInstall();
		$config->builder = ['assetsPath' => 'static'];
		$client          = exposureClient(static fn (): HttpResponse => new HttpResponse(200, '<title>Index of /static</title>'), $config->docroot);
		$none            = (new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext());
		expect($none[0]->status)->toBe(FindingStatus::Ok)->and($none[0]->title)->toContain('no assets folder');

		mkdir($tmp . '/public/static', 0777, true);
		$found = (new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext());
		expect($found[0]->status)->toBe(FindingStatus::Found);
		exposureCleanup($tmp);
	});

	test('no assets folder is ok; normal response is ok; unreachable is skipped', function (): void {
		[$tmp, $config] = exposureInstall();
		$client         = exposureClient(static fn (): HttpResponse => new HttpResponse(403, ''), $config->docroot);
		expect((new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext())[0]->status)->toBe(FindingStatus::Ok)
			->and($client->calls)->toBe(0);

		mkdir($tmp . '/public/assets', 0777, true);
		expect((new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext())[0]->status)->toBe(FindingStatus::Ok);

		$down = exposureClient(static fn (): ?HttpResponse => null, $config->docroot);
		expect((new DirectoryListingCheck($config, exposureProbe($down, $config)))->run(exposureContext())[0]->status)->toBe(FindingStatus::Skipped);
		expect((new DirectoryListingCheck($config, exposureProbe($client, $config)))->run(exposureContext(''))[0]->detail)->toBe(SiteProbe::NO_URL);
		exposureCleanup($tmp);
	});
});

test('all file exposure checks make requests', function (): void {
	[$tmp, $config] = exposureInstall();
	$probe          = exposureProbe(exposureClient(exposureServeFromDisk(), $config->docroot), $config);
	expect((new DataFolderPublicCheck($config, $probe))->makesRequests())->toBeTrue()
		->and((new SensitiveFilesPublicCheck($config, $probe))->makesRequests())->toBeTrue()
		->and((new DirectoryListingCheck($config, $probe))->makesRequests())->toBeTrue();
	exposureCleanup($tmp);
});
