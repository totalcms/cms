<?php

declare(strict_types=1);

use TotalCMS\Support\PublicHttpClient;

/**
 * A client whose DNS answers come from $dns and whose transport replays
 * $replies by URL, recording each call (url, host, port, pinned ip).
 *
 * @param array<string,list<string>> $dns
 * @param array<string,array{status: int, location?: string, body?: string}> $replies
 * @param list<array{0: string, 1: string, 2: int, 3: string}> $calls
 */
function publicClient(array $dns, array $replies, ?array &$calls = []): PublicHttpClient
{
	$calls = [];

	return new PublicHttpClient(
		static fn (string $host): array => $dns[$host] ?? [],
		static function (string $url, string $host, int $port, string $ip, array $opts) use ($replies, &$calls): array {
			$calls[] = [$url, $host, $port, $ip];
			$reply   = $replies[$url] ?? ['status' => 404];

			return ['status' => $reply['status'], 'location' => $reply['location'] ?? '', 'body' => $reply['body'] ?? ''];
		},
	);
}

describe('PublicHttpClient', function (): void {
	test('fetches a public host, pinned to the address it checked', function (): void {
		$client = publicClient(['example.com' => ['93.184.215.14']], ['https://example.com/' => ['status' => 200, 'body' => 'hi']], $calls);

		$response = $client->get('https://example.com/');

		expect($response->statusCode)->toBe(200)
			->and($response->body)->toBe('hi')
			->and($calls)->toBe([['https://example.com/', 'example.com', 443, '93.184.215.14']]);
	});

	test('refuses hosts that resolve to non-public addresses', function (string $ip): void {
		$client = publicClient(['evil.test' => [$ip]], []);

		expect(fn () => $client->get('http://evil.test/'))->toThrow(RuntimeException::class, 'non-public');
	})->with(['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.1', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1']);

	test('refuses an IP literal that is not public', function (string $url): void {
		expect(fn () => publicClient([], [])->get($url))->toThrow(RuntimeException::class, 'non-public');
	})->with(['http://127.0.0.1/', 'http://[::1]:8080/', 'http://169.254.169.254/latest/meta-data/']);

	test('one private answer among public ones is enough to refuse', function (): void {
		$client = publicClient(['split.test' => ['93.184.215.14', '10.0.0.1']], []);

		expect(fn () => $client->get('https://split.test/'))->toThrow(RuntimeException::class, 'non-public');
	});

	test('refuses other schemes, credentials and unresolvable hosts', function (string $url, string $message): void {
		expect(fn () => publicClient([], [])->get($url))->toThrow(RuntimeException::class, $message);
	})->with([
		['file:///etc/passwd', 'Only http and https'],
		['gopher://example.com/', 'Only http and https'],
		['https://user:pass@example.com/', 'credentials'],
		['https://nowhere.test/', 'does not resolve'],
	]);

	test('checks every redirect hop, so a redirect cannot reach an internal address', function (): void {
		$client = publicClient(
			['example.com' => ['93.184.215.14']],
			['https://example.com/' => ['status' => 302, 'location' => 'http://169.254.169.254/latest/']],
			$calls,
		);

		expect(fn () => $client->get('https://example.com/'))->toThrow(RuntimeException::class, 'non-public')
			->and($calls)->toHaveCount(1);
	});

	test('follows relative and absolute redirects to public hosts', function (): void {
		$client = publicClient(
			['example.com' => ['93.184.215.14'], 'www.example.com' => ['93.184.215.15']],
			[
				'http://example.com/'          => ['status' => 301, 'location' => 'https://www.example.com/'],
				'https://www.example.com/'     => ['status' => 302, 'location' => '/home'],
				'https://www.example.com/home' => ['status' => 200, 'body' => 'home'],
			],
			$calls,
		);

		expect($client->get('http://example.com/')->body)->toBe('home')
			->and(array_column($calls, 3))->toBe(['93.184.215.14', '93.184.215.15', '93.184.215.15']);
	});

	test('stops after max_redirects', function (): void {
		$client = publicClient(['loop.test' => ['93.184.215.14']], ['https://loop.test/' => ['status' => 302, 'location' => 'https://loop.test/']]);

		expect(fn () => $client->get('https://loop.test/', ['max_redirects' => 2]))->toThrow(RuntimeException::class, 'Too many redirects');
	});

	test('connects to IPv4 first when the host has both', function (): void {
		$client = publicClient(['dual.test' => ['2606:2800:220:1::1', '93.184.215.14']], ['https://dual.test/' => ['status' => 200]], $calls);
		$client->get('https://dual.test/');

		expect($calls[0][3])->toBe('93.184.215.14');
	});

	test('isPublicIp', function (string $ip, bool $public): void {
		expect(PublicHttpClient::isPublicIp($ip))->toBe($public);
	})->with([
		['93.184.215.14', true],
		['2606:2800:220:1::1', true],
		['127.0.0.1', false],
		['::ffff:10.0.0.1', false],
		['not-an-ip', false],
	]);
});
