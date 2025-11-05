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
		// Ensure no data directory exists
		recursiveDelete(cmsDataDir());

		// Try to access admin - should redirect to setup
		$response = get('/admin');

		expect($response->getStatusCode())->toBe(302);
		expect($response->getHeaderLine('Location'))->toBe('/setup/data-path');
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
		expect($body)->toContain('name="custom_path"');
		expect($body)->toContain('data-visibility="location:custom"');
	});

	it('handles setup form submission with default location', function (): void {
		$response = post('/setup/data-path', [
			'location' => 'default',
		]);

		// Should redirect to login
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			expect($location)->toContain('/admin/login');
		}
	});

	it('creates data directory with correct permissions for default location', function (): void {
		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$expectedPath = dirname($docroot) . '/tcms-data';

		post('/setup/data-path', [
			'location' => 'default',
		]);

		// Verify directory was created
		expect(file_exists($expectedPath))->toBeTrue();
		expect(is_dir($expectedPath))->toBeTrue();
		expect(is_writable($expectedPath))->toBeTrue();
	});

	it('handles setup form submission with docroot location', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => 'docroot',
		]);

		// Should redirect to login
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			expect($location)->toContain('/admin/login');
		}
	});

	it('creates data directory in docroot when selected', function (): void {
		recursiveDelete(cmsDataDir());

		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$expectedPath = $docroot . '/tcms-data';

		post('/setup/data-path', [
			'location' => 'docroot',
		]);

		// Verify directory was created
		expect(file_exists($expectedPath))->toBeTrue();
		expect(is_dir($expectedPath))->toBeTrue();
	});

	it('uses existing data directory if it already exists', function (): void {
		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$dataPath = dirname($docroot) . '/tcms-data';

		// Create directory before setup
		if (!is_dir($dataPath)) {
			mkdir($dataPath, 0755, true);
		}

		// Add a test file to verify it doesn't get overwritten
		$testFile = $dataPath . '/test.txt';
		file_put_contents($testFile, 'test content');

		$response = post('/setup/data-path', [
			'location' => 'default',
		]);

		// Should succeed
		expect($response->getStatusCode())->toBeIn([200, 302]);

		// Test file should still exist
		expect(file_exists($testFile))->toBeTrue();
		expect(file_get_contents($testFile))->toBe('test content');

		// Clean up
		unlink($testFile);
	});

	it('validates custom path is absolute', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'custom_path' => 'relative/path',
		]);

		// Should redirect back to setup with error
		expect($response->getStatusCode())->toBe(302);
		expect($response->getHeaderLine('Location'))->toBe('/setup/data-path');
	});

	it('validates custom path parent directory exists', function (): void {
		recursiveDelete(cmsDataDir());

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'custom_path' => '/nonexistent/parent/path/data',
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
		// Create data directory
		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$dataPath = dirname($docroot) . '/tcms-data';

		if (!is_dir($dataPath)) {
			mkdir($dataPath, 0755, true);
		}

		// Should not redirect to setup
		$response = get('/admin');

		// Should proceed to normal auth flow (login page or admin)
		expect($response->getStatusCode())->toBeIn([200, 302]);

		if ($response->getStatusCode() === 302) {
			$location = $response->getHeaderLine('Location');
			// Should redirect to login, not setup
			expect($location)->not->toBe('/setup/data-path');
		}
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
		recursiveDelete(cmsDataDir());

		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$customPath = dirname($docroot) . '/my-custom-cms-folder';

		// Clean up if exists
		if (is_dir($customPath)) {
			recursiveDelete($customPath);
		}

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'custom_path' => $customPath,
		]);

		// Should succeed
		expect($response->getStatusCode())->toBeIn([200, 302]);

		// Verify custom folder was created
		expect(file_exists($customPath))->toBeTrue();
		expect(is_dir($customPath))->toBeTrue();

		// Clean up
		if (is_dir($customPath)) {
			recursiveDelete($customPath);
		}
	});

	it('saves custom path to tcms.php', function (): void {
		recursiveDelete(cmsDataDir());

		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$customPath = dirname($docroot) . '/custom-data';
		$tcmsFile = $docroot . '/tcms.php';

		// Clean up
		if (file_exists($tcmsFile)) {
			unlink($tcmsFile);
		}
		if (is_dir($customPath)) {
			recursiveDelete($customPath);
		}

		$response = post('/setup/data-path', [
			'location' => 'custom',
			'custom_path' => $customPath,
		]);

		// Should succeed
		expect($response->getStatusCode())->toBeIn([200, 302]);

		// Verify tcms.php was created with custom path
		expect(file_exists($tcmsFile))->toBeTrue();

		$config = require $tcmsFile;
		expect($config)->toBeArray();
		expect($config['datadir'])->toBe($customPath);

		// Clean up
		if (file_exists($tcmsFile)) {
			unlink($tcmsFile);
		}
		if (is_dir($customPath)) {
			recursiveDelete($customPath);
		}
	});

	it('does not save tcms.php for default location', function (): void {
		recursiveDelete(cmsDataDir());

		$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$tcmsFile = $docroot . '/tcms.php';

		// Clean up
		if (file_exists($tcmsFile)) {
			unlink($tcmsFile);
		}

		$response = post('/setup/data-path', [
			'location' => 'default',
		]);

		// Should succeed
		expect($response->getStatusCode())->toBeIn([200, 302]);

		// tcms.php should NOT be created for default location
		expect(file_exists($tcmsFile))->toBeFalse();
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
});
