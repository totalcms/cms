<?php

declare(strict_types=1);

use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Service\SiteProbe;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

function probeConfig(string $docroot, string $domain = 'example.test', string $url = 'https://example.test/'): Config
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->docroot = $docroot;
	$config->domain  = $domain;
	$config->url     = $url;

	return $config;
}

function probeContext(string $base = 'https://example.test'): SecurityContext
{
	return new SecurityContext($base, 'example.test', true, true, '');
}

function probeClient(?HttpResponse $response, ?Throwable $throw = null): object
{
	return new class($response, $throw) implements HttpClientInterface {
		public array $options = [];
		public int $calls     = 0;

		public function __construct(private ?HttpResponse $response, private ?Throwable $throw)
		{
		}

		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$this->options = $options;
			$this->calls++;
			if ($this->throw !== null) {
				throw $this->throw;
			}

			return $this->response;
		}
	};
}

function probeTmp(): string
{
	$dir = sys_get_temp_dir() . '/tcms-probe-' . bin2hex(random_bytes(4));
	mkdir($dir . '/public/tcms-data', 0777, true);

	return realpath($dir);
}

describe('SiteProbe', function (): void {
	test('urlFor returns null when the base URL is unknown', function (): void {
		$tmp   = probeTmp();
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public'));

		expect($probe->urlFor(probeContext(''), $tmp . '/public/x.txt'))->toBeNull();
	});

	test('urlFor yields null for the CLI on a site with an unknown domain', function (): void {
		$tmp     = probeTmp();
		$config  = probeConfig($tmp . '/public', 'unknown', '');
		$context = SecurityContext::fromCli($config);

		expect($context->baseUrl)->toBe('');
		expect((new SiteProbe(probeClient(null), $config))->urlFor($context, $tmp . '/public/x.txt'))->toBeNull();
	});

	test('urlFor maps a path under a docroot with a trailing slash', function (): void {
		$tmp = probeTmp();
		file_put_contents($tmp . '/public/tcms-data/x y.txt', 'x');
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public/'));

		expect($probe->urlFor(probeContext(), $tmp . '/public/tcms-data/x y.txt'))
			->toBe('https://example.test/tcms-data/x%20y.txt');
	});

	test('urlFor maps a not-yet-existing file via its parent directory', function (): void {
		$tmp   = probeTmp();
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public/'));

		expect($probe->urlFor(probeContext(), $tmp . '/public/tcms-data/new.txt'))
			->toBe('https://example.test/tcms-data/new.txt');
	});

	test('urlFor returns null outside the docroot', function (): void {
		$tmp = probeTmp();
		mkdir($tmp . '/data');
		file_put_contents($tmp . '/data/x.txt', 'x');
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public'));

		expect($probe->urlFor(probeContext(), $tmp . '/public/data/x.txt'))->toBe('https://example.test/data/x.txt')
			->and($probe->insideDocroot($tmp . '/public/data'))->toBeTrue()
			->and($probe->urlFor(probeContext(), $tmp . '/data/x.txt'))->toBeNull();
		expect($probe->insideDocroot($tmp . '/data/x.txt'))->toBeFalse();
	});

	test('urlFor does not match a sibling directory sharing the docroot prefix', function (): void {
		$tmp = probeTmp();
		mkdir($tmp . '/public-other');
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public'));

		expect($probe->insideDocroot($tmp . '/public-other'))->toBeFalse();
	});

	test('urlFor returns a URL for a folder reached through a symlink inside the docroot', function (): void {
		$tmp = probeTmp();
		mkdir($tmp . '/data');
		file_put_contents($tmp . '/data/x.txt', 'x');
		symlink($tmp . '/data', $tmp . '/public/data');
		$probe = new SiteProbe(probeClient(null), probeConfig($tmp . '/public'));

		expect($probe->urlFor(probeContext(), $tmp . '/public/data/x.txt'))->toBe('https://example.test/data/x.txt')
			->and($probe->insideDocroot($tmp . '/public/data'))->toBeTrue()
			->and($probe->urlFor(probeContext(), $tmp . '/data/x.txt'))->toBeNull();
	});

	test('get returns null when the client throws', function (): void {
		$probe = new SiteProbe(probeClient(null, new RuntimeException('boom')), probeConfig('/tmp'));

		expect($probe->get('https://example.test/x'))->toBeNull();
	});

	test('get returns null on status 0', function (): void {
		$probe = new SiteProbe(probeClient(new HttpResponse(0, '')), probeConfig('/tmp'));

		expect($probe->get('https://example.test/x'))->toBeNull();
	});

	test('get returns the response and passes the timeout and no-redirect options', function (): void {
		$client = probeClient(new HttpResponse(200, 'ok', ['x-a' => 'b']));
		$probe  = new SiteProbe($client, probeConfig('/tmp'));

		expect($probe->get('https://example.test/x')?->statusCode)->toBe(200);
		expect($client->options['timeout'])->toBe(3);
		expect($client->options['connect_timeout'])->toBe(3);
		expect($client->options['follow_redirects'])->toBeFalse();
	});

	test('after a transport failure later gets return null without a request until reset', function (): void {
		$client = probeClient(null, new RuntimeException('timed out'));
		$probe  = new SiteProbe($client, probeConfig('/tmp'));

		expect($probe->get('https://example.test/a'))->toBeNull()
			->and($probe->get('https://example.test/b'))->toBeNull()
			->and($probe->get('https://example.test/c'))->toBeNull()
			->and($client->calls)->toBe(1);

		$probe->reset();
		expect($probe->get('https://example.test/d'))->toBeNull()
			->and($client->calls)->toBe(2);
	});

	test('a status 0 response also counts as unreachable for the rest of the audit', function (): void {
		$client = probeClient(new HttpResponse(0, ''));
		$probe  = new SiteProbe($client, probeConfig('/tmp'));

		$probe->get('https://example.test/a');
		$probe->get('https://example.test/b');

		expect($client->calls)->toBe(1);
	});

	test('an HTTP error response does not stop later requests', function (): void {
		$client = probeClient(new HttpResponse(404, ''));
		$probe  = new SiteProbe($client, probeConfig('/tmp'));

		$probe->get('https://example.test/a');
		$probe->get('https://example.test/b');

		expect($client->calls)->toBe(2);
	});
});
