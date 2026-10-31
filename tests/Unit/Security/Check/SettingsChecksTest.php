<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Security\Check\DebugOnCheck;
use TotalCMS\Domain\Security\Check\DevModeOnCheck;
use TotalCMS\Domain\Security\Check\NoHttpsCheck;
use TotalCMS\Domain\Security\Check\SecurityHeadersCheck;
use TotalCMS\Domain\Security\Check\VersionBehindCheck;
use TotalCMS\Domain\Security\Check\XmlRpcOnCheck;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Domain\Update\Data\UpdateInfo;
use TotalCMS\Domain\Update\Service\UpdateChecker;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function settingsConfig(array $props = []): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	foreach ($props as $key => $value) {
		$config->$key = $value;
	}

	return $config;
}

function settingsContext(string $host = 'mysite.com', bool $https = true, string $base = 'https://mysite.com', string $software = 'Apache/2.4'): SecurityContext
{
	return new SecurityContext($base, $host, $https, true, $software);
}

/** @param array<string,string>|null $headers null makes the client throw */
function settingsProbe(?array $headers, ?int &$calls = null): SiteProbe
{
	$client = new class($headers) implements HttpClientInterface {
		public int $calls = 0;

		public function __construct(private ?array $headers)
		{
		}

		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$this->calls++;
			if ($this->headers === null) {
				throw new RuntimeException('refused');
			}

			return new HttpResponse(200, '<html></html>', $this->headers);
		}
	};

	return new SiteProbe($client, settingsConfig());
}

function allHeaders(): array
{
	return [
		'x-frame-options'           => 'SAMEORIGIN',
		'x-content-type-options'    => 'nosniff',
		'referrer-policy'           => 'same-origin',
		'strict-transport-security' => 'max-age=31536000',
	];
}

describe('DebugOnCheck', function (): void {
	test('debug on a live host is a warning', function (): void {
		$f = (new DebugOnCheck(settingsConfig(['debug' => true])))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Found)
			->and($f->severity)->toBe(Severity::Warning)
			->and($f->id)->toBe('debug-on');
	});

	test('debug on a .test host is ok', function (): void {
		$f = (new DebugOnCheck(settingsConfig(['debug' => true])))->run(settingsContext('site.test'))[0];
		expect($f->status)->toBe(FindingStatus::Ok);
	});

	test('debug off is ok', function (): void {
		$f = (new DebugOnCheck(settingsConfig(['debug' => false])))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Ok);
	});
});

describe('DevModeOnCheck', function (): void {
	function devMode(bool $active, int $remaining = 0): DevModeManager
	{
		$mock = test()->createMock(DevModeManager::class);
		$mock->method('isDevModeActive')->willReturn($active);
		$mock->method('getRemainingTime')->willReturn($remaining);

		return $mock;
	}

	test('dev mode on a live host is info with minutes left', function (): void {
		$f = (new DevModeOnCheck(devMode(true, 1800)))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Found)
			->and($f->severity)->toBe(Severity::Info)
			->and($f->id)->toBe('dev-mode-on')
			->and($f->detail)->toContain('30');
	});

	test('dev mode off is ok', function (): void {
		expect((new DevModeOnCheck(devMode(false)))->run(settingsContext())[0]->status)->toBe(FindingStatus::Ok);
	});

	test('dev mode on a local host is ok', function (): void {
		expect((new DevModeOnCheck(devMode(true, 60)))->run(settingsContext('localhost'))[0]->status)->toBe(FindingStatus::Ok);
	});
});

describe('NoHttpsCheck', function (): void {
	test('plain http on a live host is a warning', function (): void {
		$f = (new NoHttpsCheck())->run(settingsContext('mysite.com', false, 'http://mysite.com'))[0];
		expect($f->status)->toBe(FindingStatus::Found)
			->and($f->severity)->toBe(Severity::Warning)
			->and($f->id)->toBe('no-https');
	});

	test('https is ok', function (): void {
		expect((new NoHttpsCheck())->run(settingsContext())[0]->status)->toBe(FindingStatus::Ok);
	});

	test('plain http on a local host is ok', function (): void {
		expect((new NoHttpsCheck())->run(settingsContext('site.test', false, 'http://site.test'))[0]->status)->toBe(FindingStatus::Ok);
	});

	test('unknown address on the CLI is skipped', function (): void {
		$f = (new NoHttpsCheck())->run(new SecurityContext('', '', false, false, ''))[0];
		expect($f->status)->toBe(FindingStatus::Skipped)
			->and($f->detail)->toBe(SiteProbe::NO_URL);
	});
});

describe('SecurityHeadersCheck', function (): void {
	test('all headers present is ok', function (): void {
		$f = (new SecurityHeadersCheck(settingsProbe(allHeaders())))->run(settingsContext());
		expect($f)->toHaveCount(1)
			->and($f[0]->status)->toBe(FindingStatus::Ok);
	});

	test('missing headers are listed by name', function (): void {
		$headers = allHeaders();
		unset($headers['x-frame-options'], $headers['referrer-policy']);
		$f = (new SecurityHeadersCheck(settingsProbe($headers)))->run(settingsContext());

		expect($f)->toHaveCount(1)
			->and($f[0]->status)->toBe(FindingStatus::Found)
			->and($f[0]->severity)->toBe(Severity::Info)
			->and($f[0]->id)->toBe('security-headers')
			->and($f[0]->detail)->toContain('X-Frame-Options')
			->and($f[0]->detail)->toContain('Referrer-Policy')
			->and($f[0]->detail)->not->toContain('X-Content-Type-Options');
	});

	test('HSTS finding only on https', function (): void {
		$headers = allHeaders();
		unset($headers['strict-transport-security']);

		$https = (new SecurityHeadersCheck(settingsProbe($headers)))->run(settingsContext());
		expect($https)->toHaveCount(1)
			->and($https[0]->id)->toBe('security-headers-hsts')
			->and($https[0]->status)->toBe(FindingStatus::Found)
			->and($https[0]->fix)->toContain('Strict-Transport-Security');

		$http = (new SecurityHeadersCheck(settingsProbe($headers)))->run(settingsContext('mysite.com', false, 'http://mysite.com'));
		expect($http)->toHaveCount(1)
			->and($http[0]->status)->toBe(FindingStatus::Ok);
	});

	test('nginx gets add_header lines', function (): void {
		$f = (new SecurityHeadersCheck(settingsProbe([])))->run(settingsContext('mysite.com', true, 'https://mysite.com', 'nginx/1.25'));
		expect($f[0]->fix)->toContain('add_header');
	});

	test('apache gets Header lines', function (): void {
		$f = (new SecurityHeadersCheck(settingsProbe([])))->run(settingsContext());
		expect($f[0]->fix)->toContain('Header always set');
	});

	test('unreachable site is skipped, not ok', function (): void {
		$f = (new SecurityHeadersCheck(settingsProbe(null)))->run(settingsContext());
		expect($f[0]->status)->toBe(FindingStatus::Skipped)
			->and($f[0]->detail)->toBe(SiteProbe::UNREACHABLE);
	});

	test('unknown address is skipped without a request', function (): void {
		$f = (new SecurityHeadersCheck(settingsProbe(allHeaders())))->run(new SecurityContext('', '', false, false, ''));
		expect($f[0]->status)->toBe(FindingStatus::Skipped)
			->and($f[0]->detail)->toBe(SiteProbe::NO_URL);
	});

	test('it makes requests', function (): void {
		expect((new SecurityHeadersCheck(settingsProbe([])))->makesRequests())->toBeTrue();
	});
});

describe('XmlRpcOnCheck', function (): void {
	test('xmlrpc on is info', function (): void {
		$f = (new XmlRpcOnCheck(settingsConfig(['xmlrpc' => ['enable' => true]])))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Found)
			->and($f->severity)->toBe(Severity::Info)
			->and($f->id)->toBe('xmlrpc-on');
	});

	test('xmlrpc off is ok', function (): void {
		expect((new XmlRpcOnCheck(settingsConfig(['xmlrpc' => ['enable' => false]])))->run(settingsContext())[0]->status)->toBe(FindingStatus::Ok);
		expect((new XmlRpcOnCheck(settingsConfig(['xmlrpc' => []])))->run(settingsContext())[0]->status)->toBe(FindingStatus::Ok);
	});
});

describe('VersionBehindCheck', function (): void {
	function updateInfo(bool $available, string $version = '3.6.9'): UpdateInfo
	{
		return new UpdateInfo($available, $version, '2026-10-01', 'patch', '', '', '');
	}

	test('a newer version is a warning naming it', function (): void {
		$checker = test()->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(updateInfo(true));
		$f = (new VersionBehindCheck($checker))->run(settingsContext())[0];

		expect($f->status)->toBe(FindingStatus::Found)
			->and($f->severity)->toBe(Severity::Warning)
			->and($f->id)->toBe('version-behind')
			->and($f->detail)->toContain('3.6.9');
	});

	test('no update reported is ok, without claiming up to date', function (): void {
		$checker = test()->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willReturn(updateInfo(false));
		$f = (new VersionBehindCheck($checker))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Ok)
			->and($f->title)->toBe('No newer version was reported');
	});

	test('update check failure is skipped', function (): void {
		$checker = test()->createMock(UpdateChecker::class);
		$checker->method('checkForUpdate')->willThrowException(new RuntimeException('offline'));
		$f = (new VersionBehindCheck($checker))->run(settingsContext())[0];
		expect($f->status)->toBe(FindingStatus::Skipped);
	});

	test('it counts as request-making (cold cache hits the network)', function (): void {
		expect((new VersionBehindCheck(test()->createMock(UpdateChecker::class)))->makesRequests())->toBeTrue();
	});
});

describe('an unknown host', function (): void {
	test('debug on with no known host is skipped, not a warning', function (): void {
		$f = (new DebugOnCheck(settingsConfig(['debug' => true])))->run(settingsContext('', true, ''))[0];
		expect($f->status)->toBe(FindingStatus::Skipped)
			->and($f->id)->toBe('debug-on')
			->and($f->detail)->toBe(SiteProbe::NO_URL);
	});

	test('debug off with no known host is still ok', function (): void {
		expect((new DebugOnCheck(settingsConfig(['debug' => false])))->run(settingsContext('', true, ''))[0]->status)->toBe(FindingStatus::Ok);
	});

	test('dev mode on with no known host is skipped', function (): void {
		$f = (new DevModeOnCheck(devMode(true, 60)))->run(settingsContext('', true, ''))[0];
		expect($f->status)->toBe(FindingStatus::Skipped)
			->and($f->detail)->toBe(SiteProbe::NO_URL);
	});

	test('no-https with a base URL but no host is skipped', function (): void {
		$f = (new NoHttpsCheck())->run(new SecurityContext('http://', '', false, false, ''))[0];
		expect($f->status)->toBe(FindingStatus::Skipped);
	});

	test('a bare IP host counts as local', function (): void {
		expect((new DebugOnCheck(settingsConfig(['debug' => true])))->run(settingsContext('172.17.0.2', false, 'http://172.17.0.2'))[0]->status)->toBe(FindingStatus::Ok);
	});
});
