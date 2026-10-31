<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Support\Config;

function secContextConfig(bool $https = false): Config
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->isHttps = $https;

	return $config;
}

describe('SecurityContext::fromRequest', function (): void {
	test('a plain https request is https', function (): void {
		$context = SecurityContext::fromRequest(new ServerRequest('GET', 'https://mysite.com/admin', [], null, '1.1', ['SERVER_SOFTWARE' => 'Apache']), secContextConfig(true));

		expect($context->https)->toBeTrue()
			->and($context->baseUrl)->toBe('https://mysite.com')
			->and($context->host)->toBe('mysite.com')
			->and($context->fromWeb)->toBeTrue()
			->and($context->serverSoftware)->toBe('Apache');
	});

	test('a plain http request is http', function (): void {
		$context = SecurityContext::fromRequest(new ServerRequest('GET', 'http://mysite.com:8080/admin'), secContextConfig());

		expect($context->https)->toBeFalse()
			->and($context->baseUrl)->toBe('http://mysite.com:8080');
	});

	test('an http request behind an https proxy uses the config is_https', function (): void {
		$context = SecurityContext::fromRequest(new ServerRequest('GET', 'http://mysite.com/admin'), secContextConfig(true));

		expect($context->https)->toBeTrue()
			->and($context->baseUrl)->toBe('https://mysite.com');
	});
});

describe('SecurityContext hosts', function (): void {
	test('non-routable hosts count as local', function (): void {
		foreach (['localhost', '127.0.0.1', '::1', '[::1]', '10.0.0.5', '172.17.0.2', 'app.localhost', 'foo.test', 'example.com'] as $host) {
			expect((new SecurityContext('', $host, false, false, ''))->isLocalHost())->toBeTrue();
		}
		expect((new SecurityContext('', 'mysite.com', false, false, ''))->isLocalHost())->toBeFalse();
	});

	test('an empty host is unknown, not local', function (): void {
		$context = new SecurityContext('', '', false, false, '');

		expect($context->hostUnknown())->toBeTrue()
			->and($context->isLocalHost())->toBeFalse();
	});
});
