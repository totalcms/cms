<?php

use TotalCMS\Support\CurlHttpClient;
use TotalCMS\Support\HttpResponse;

describe('CurlHttpClient', function (): void {
	test('can be instantiated', function (): void {
		$client = new CurlHttpClient();
		expect($client)->toBeInstanceOf(CurlHttpClient::class);
	});

	test('returns HttpResponse from request', function (): void {
		// Lightweight end-to-end smoke test against a known, owned URL. When the
		// network is unreachable we skip rather than fail — that's an environment
		// problem, not a CurlHttpClient bug.
		$client = new CurlHttpClient();

		try {
			$response = $client->request('GET', 'https://docs.totalcms.co', [
				'timeout'          => 10,
				'connect_timeout'  => 5,
				'follow_redirects' => true,
				'user_agent'       => 'TotalCMS-Test/1.0',
			]);
		} catch (RuntimeException $e) {
			$this->markTestSkipped('Network unavailable or endpoint unreachable: ' . $e->getMessage());
		}

		expect($response)->toBeInstanceOf(HttpResponse::class);
		expect($response->statusCode)->toBe(200);
		expect($response->body)->not->toBe('');
	})->skip(getenv('CI') !== false, 'Skipped in CI - requires network');

	test('exposes response headers lowercased', function (): void {
		$dir = sys_get_temp_dir() . '/tcms-curl-hdr-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents($dir . '/router.php', '<?php header("X-Probe-Header: yes"); echo "hi";');
		$port = random_int(20000, 40000);
		$proc = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		if (!is_resource($proc)) {
			@unlink($dir . '/router.php');
			@rmdir($dir);
			$this->markTestSkipped('Could not start the PHP built-in server.');
		}

		// Wait until the server listens (up to ~5 s) instead of a fixed sleep.
		$listening = false;
		for ($i = 0; $i < 50 && !$listening; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if (is_resource($socket)) {
				fclose($socket);
				$listening = true;
			} else {
				usleep(100000);
			}
		}
		if (!$listening) {
			proc_terminate($proc);
			proc_close($proc);
			@unlink($dir . '/router.php');
			@rmdir($dir);
			$this->markTestSkipped('The PHP built-in server never started listening.');
		}

		try {
			$response = (new CurlHttpClient())->request('GET', 'http://127.0.0.1:' . $port . '/', ['timeout' => 3, 'connect_timeout' => 3]);
			expect($response->headers['x-probe-header'] ?? null)->toBe('yes');
		} finally {
			proc_terminate($proc);
			proc_close($proc);
			@unlink($dir . '/router.php');
			@rmdir($dir);
		}
	});

	test('throws RuntimeException on connection failure', function (): void {
		$client = new CurlHttpClient();
		$client->request('GET', 'https://this-domain-does-not-exist-totalcms-test.invalid', [
			'timeout'         => 2,
			'connect_timeout' => 1,
		]);
	})->throws(RuntimeException::class);
});
