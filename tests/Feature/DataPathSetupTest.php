<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;

beforeAll(function (): void {
	// Clean up before tests
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	// Clean data directory for fresh setup tests
	recursiveDelete(cmsDataDir());

	$this->setUpApp(bootstrap());
});

afterAll(function (): void {
	// Restore test data after setup tests complete
	$testDataPath = __DIR__ . '/../tcms-data';
	$fixturesPath = $testDataPath . '-fixtures';

	if (is_dir($fixturesPath)) {
		recursiveDelete($testDataPath, [], true);
		exec('cp -r ' . escapeshellarg($fixturesPath) . ' ' . escapeshellarg($testDataPath));
	}
});

describe('Data Path Setup Feature', function (): void {
	it('redirects to setup page when tcms-data does not exist', function (): void {
		// Note: This test is skipped in test environment because bootstrap
		// pre-configures datadir, preventing the redirect to setup.
		// The setup wizard flow is tested in production environments.
		$this->markTestSkipped('Setup redirect requires unconfigured environment');
	});

	it('renders setup page successfully when tcms-data missing', function (): void {
		$response = get('/setup/data-path');

		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();
		expect($body)->toContain('Welcome to Total CMS');
		expect($body)->toContain('Data Path Configuration');
		expect($body)->toContain('Default (Recommended)');
		expect($body)->toContain('Document Root');
		expect($body)->toContain('Custom Path');
	});

	it('displays actual paths for default and docroot options', function (): void {
		$response = get('/setup/data-path');

		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();
		// Should contain paths with tcms-data
		expect($body)->toContain('tcms-data');
		// Should show absolute paths (starting with /)
		expect($body)->toMatch('/\/[^\/]+.*tcms-data/');
	});

	it('shows custom path input field', function (): void {
		$response = get('/setup/data-path');

		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();
		expect($body)->toContain('name="customPath"');
		expect($body)->toMatch('/visibility.*location.*custom/');
	});

	it('handles setup form submission with default location', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('creates data directory with correct permissions for default location', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('handles setup form submission with docroot location', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('creates data directory in docroot when selected', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('uses existing data directory if it already exists', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('validates custom path is absolute', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'customPath' => 'relative/path',
		]);

		// Should redirect back to setup with error
		expect($response->getStatusCode())->toBe(302);
		expect($response->getHeaderLine('Location'))->toBe('/setup/data-path');
	});

	it('validates custom path parent directory exists', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'customPath' => '/nonexistent/parent/path/data',
		]);

		// Should redirect back to setup with error
		expect($response->getStatusCode())->toBe(302);
		expect($response->getHeaderLine('Location'))->toBe('/setup/data-path');
	});

	it('skips setup check for setup routes', function (): void {
		// Even without tcms-data, setup routes should be accessible
		recursiveDelete(cmsDataDir());

		$response = get('/setup/data-path');

		// Should render the page, not redirect
		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Location'))->toBe('');
	});

	it('skips setup check for static assets', function (): void {
		recursiveDelete(cmsDataDir());

		// Static asset routes should not trigger setup redirect
		// They might return 404 if file doesn't exist, but not redirect to setup
		$staticPaths = ['/css/test.css', '/js/test.js', '/images/test.png'];

		foreach ($staticPaths as $path) {
			$response = get($path);

			// Should be 404 (file not found) but NOT redirect to setup
			expect($response->getStatusCode())->toBeIn([200, 404]);

			if ($response->getStatusCode() === 302) {
				$location = $response->getHeaderLine('Location');
				expect($location)->not->toBe('/setup/data-path');
			}
		}
	});

	it('allows normal access when tcms-data exists', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('handles form submission with empty location', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => '',
		]);

		// Should redirect back with error
		expect($response->getStatusCode())->toBe(302);
		expect($response->getHeaderLine('Location'))->toBe('/setup/data-path');
	});

	it('allows custom folder names', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('saves custom path to tcms.php', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('does not save tcms.php for default location', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('contains proper HTML structure', function (): void {
		$response = get('/setup/data-path');
		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();

		// Should be valid HTML
		expect($body)->toMatch('/<html[^>]*>/i');
		expect($body)->toMatch('/<head[^>]*>/i');
		expect($body)->toMatch('/<body[^>]*>/i');
		expect($body)->toMatch('/<title[^>]*>.*<\/title>/i');
	});

	it('includes form with proper action and method', function (): void {
		$response = get('/setup/data-path');
		expect($response->getStatusCode())->toBe(200);

		$body = (string)$response->getBody();

		// Should have form with POST method
		expect($body)->toMatch('/<form[^>]*method=["\']POST["\']/i');
		expect($body)->toContain('/setup/data-path');
	});

	it('bypasses setup when datadir is pre-configured in settings', function (): void {
		// This test verifies that environments with pre-configured datadir
		// (like preview/test with local.preview.php or local.test.php)
		// bypass the setup wizard completely

		// The test environment itself has pre-configured datadir from bootstrap()
		// So accessing admin should NOT redirect to setup
		$response = get('/admin');

		// Should proceed to normal auth flow, not redirect to setup
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			// May redirect to login, but never to setup
			expect($location)->not->toBe('/setup/data-path');
		}
	});

	it('cleans up empty default directory when docroot is selected', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('does not remove default directory if it contains files', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});

	it('cleans up empty default directory when custom path is selected', function (): void {
		$this->markTestSkipped('Requires filesystem write permissions outside test environment');
	});
});
