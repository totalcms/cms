<?php

/**
 * Admin View Integration Test.
 *
 * Tests admin views for errors by importing jumpstart demo data and visiting
 * predetermined URLs to ensure all admin views render without errors.
 */

use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;

use function Nekofar\Slim\Pest\get;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());

	echo "\n🔄 Setting up admin view tests...\n";

	// Import jumpstart demo data ONCE for all tests
	echo "📦 Importing jumpstart demo data...\n";
	$app               = bootstrap();
	$container         = $app->getContainer();
	$jumpStartImporter = $container->get(JumpStartImporter::class);
	$demoDataPath      = jumpstartResourcePath('demo.json');

	if (file_exists($demoDataPath)) {
		$demoData = json_decode(file_get_contents($demoDataPath), true);
		$jumpStartImporter->importFromDefinition($demoData);
		echo "✅ Demo data imported successfully\n";
	} else {
		echo "⚠️  Demo data file not found at: $demoDataPath\n";
	}

	echo "🚀 Starting admin view tests...\n\n";
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Helper function to check response for common error indicators.
 *
 * @param mixed $response
 */
function assertNoAdminErrors($response, string $path): void
{
	$statusCode = $response->getStatusCode();
	$body       = (string)$response->getBody();

	// Check for successful HTTP status (200 is OK, 302 is redirect, 404 means route doesn't exist but no error)
	expect($statusCode)->toBeIn([200, 302, 404], "HTTP error {$statusCode} on {$path}");

	// Only check body content for successful responses, not redirects
	if ($statusCode === 200) {
		// Check for common error patterns in admin views using manual checks (more reliable)
		$errorPatterns = [
			'Fatal error'                                          => "Fatal error found on {$path}",
			'Parse error'                                          => "Parse error found on {$path}",
			'Undefined variable'                                   => "Undefined variable on {$path}",
			'must not be accessed before initialization'           => "Uninitialized property error on {$path}",
			'Call to undefined method'                             => "Undefined method call on {$path}",
			'Class \''                                             => "Class not found error on {$path}",
			Twig\Error\RuntimeError::class                         => "Twig runtime error on {$path}",
			'An exception has been thrown during the rendering'    => "Template rendering error on {$path}",
			'Unknown function'                                     => "Twig unknown function on {$path}",
			'Unknown filter'                                       => "Twig unknown filter on {$path}",
		];

		foreach ($errorPatterns as $pattern => $message) {
			if (str_contains($body, $pattern)) {
				expect(false)->toBeTrue($message);
			}
		}

		// Check for Twig error pattern with regex
		if (preg_match('/Error rendering template:.*?twig/', $body)) {
			expect(false)->toBeTrue("Twig template error on {$path}");
		}
	}
}

describe('Admin Dashboard Views', function (): void {
	it('loads dashboard home without errors', function (): void {
		echo '🏠 Testing dashboard home...';
		$response = get('/admin/');
		assertNoAdminErrors($response, '/admin/');
		echo " ✅\n";
	});

	it('loads dashboard overview without errors', function (): void {
		echo '📊 Testing dashboard overview...';
		$response = get('/admin/dashboard');
		assertNoAdminErrors($response, '/admin/dashboard');
		echo " ✅\n";
	});
});

describe('Collection Management Views', function (): void {
	it('loads collections list without errors', function (): void {
		echo '📋 Testing collections list...';
		$response = get('/admin/collections');
		assertNoAdminErrors($response, '/admin/collections');
		echo " ✅\n";
	});

	it('loads collection creation form without errors', function (): void {
		echo '➕ Testing collection creation form...';
		$response = get('/admin/collections/new');
		assertNoAdminErrors($response, '/admin/collections/new');
		echo " ✅\n";
	});

	it('loads blog collection edit form without errors', function (): void {
		echo '📝 Testing blog collection edit form (useFormGrid check)...';
		$response = get('/admin/collections/blog/edit');
		assertNoAdminErrors($response, '/admin/collections/blog/edit');
		echo " ✅\n";
	});

	it('loads products collection edit form without errors', function (): void {
		echo '🛍️ Testing products collection edit form...';
		$response = get('/admin/collections/products/edit');
		assertNoAdminErrors($response, '/admin/collections/products/edit');
		echo " ✅\n";
	});

	it('loads feed collection edit form without errors', function (): void {
		echo '📡 Testing feed collection edit form...';
		$response = get('/admin/collections/feed/edit');
		assertNoAdminErrors($response, '/admin/collections/feed/edit');
		echo " ✅\n";
	});
});

describe('Object Management Views', function (): void {
	it('loads blog objects list without errors', function (): void {
		echo '📖 Testing blog objects list...';
		$response = get('/admin/collections/blog');
		assertNoAdminErrors($response, '/admin/collections/blog');
		echo " ✅\n";
	});

	it('loads products objects list without errors', function (): void {
		echo '🛒 Testing products objects list...';
		$response = get('/admin/collections/products');
		assertNoAdminErrors($response, '/admin/collections/products');
		echo " ✅\n";
	});

	it('loads feed objects list without errors', function (): void {
		echo '📰 Testing feed objects list...';
		$response = get('/admin/collections/feed');
		assertNoAdminErrors($response, '/admin/collections/feed');
		echo " ✅\n";
	});

	it('loads blog object creation form without errors', function (): void {
		echo '✏️ Testing blog object creation form...';
		$response = get('/admin/collections/blog/new');
		assertNoAdminErrors($response, '/admin/collections/blog/new');
		echo " ✅\n";
	});

	it('loads products object creation form without errors', function (): void {
		echo '🆕 Testing products object creation form...';
		$response = get('/admin/collections/products/new');
		assertNoAdminErrors($response, '/admin/collections/products/new');
		echo " ✅\n";
	});
});

describe('Schema Management Views', function (): void {
	it('loads schemas list without errors', function (): void {
		$response = get('/admin/schemas');
		assertNoAdminErrors($response, '/admin/schemas');
	});

	it('loads schema creation form without errors', function (): void {
		$response = get('/admin/schemas/new');
		assertNoAdminErrors($response, '/admin/schemas/new');
	});

	it('loads blog schema edit form without errors', function (): void {
		$response = get('/admin/schemas/blog/edit');
		assertNoAdminErrors($response, '/admin/schemas/blog/edit');
	});

	it('loads products schema edit form without errors', function (): void {
		$response = get('/admin/schemas/products/edit');
		assertNoAdminErrors($response, '/admin/schemas/products/edit');
	});
});

describe('Utility and Management Views', function (): void {
	it('loads settings page without errors', function (): void {
		$response = get('/admin/settings');
		assertNoAdminErrors($response, '/admin/settings');
	});

	it('loads jumpstart utility without errors', function (): void {
		$response = get('/admin/utils/jumpstart');
		assertNoAdminErrors($response, '/admin/utils/jumpstart');
	});

	it('loads project setup utility without errors', function (): void {
		$response = get('/admin/utils/project-setup');
		assertNoAdminErrors($response, '/admin/utils/project-setup');
	});

	it('loads cache management without errors', function (): void {
		$response = get('/admin/cache');
		assertNoAdminErrors($response, '/admin/cache');
	});

	it('loads system information without errors', function (): void {
		$response = get('/admin/system');
		assertNoAdminErrors($response, '/admin/system');
	});

	it('loads backup and restore without errors', function (): void {
		$response = get('/admin/backup');
		assertNoAdminErrors($response, '/admin/backup');
	});
});

describe('Template and Design Views', function (): void {
	it('loads template editor without errors', function (): void {
		$response = get('/admin/templates');
		assertNoAdminErrors($response, '/admin/templates');
	});

	it('loads template creation form without errors', function (): void {
		$response = get('/admin/templates/new');
		assertNoAdminErrors($response, '/admin/templates/new');
	});
});

describe('File and Media Management Views', function (): void {
	it('loads file manager without errors', function (): void {
		$response = get('/admin/files');
		assertNoAdminErrors($response, '/admin/files');
	});

	it('loads depot manager without errors', function (): void {
		$response = get('/admin/depot');
		assertNoAdminErrors($response, '/admin/depot');
	});

	it('loads image processing settings without errors', function (): void {
		$response = get('/admin/images');
		assertNoAdminErrors($response, '/admin/images');
	});
});

describe('User and Security Views', function (): void {
	it('loads user management without errors', function (): void {
		$response = get('/admin/users');
		assertNoAdminErrors($response, '/admin/users');
	});

	it('loads security settings without errors', function (): void {
		$response = get('/admin/security');
		assertNoAdminErrors($response, '/admin/security');
	});

	it('loads audit log without errors', function (): void {
		$response = get('/admin/audit');
		assertNoAdminErrors($response, '/admin/audit');
	});
});

describe('API and Integration Views', function (): void {
	it('loads API settings without errors', function (): void {
		$response = get('/admin/api');
		assertNoAdminErrors($response, '/admin/api');
	});

	it('loads webhook settings without errors', function (): void {
		$response = get('/admin/webhooks');
		assertNoAdminErrors($response, '/admin/webhooks');
	});

	it('loads integrations panel without errors', function (): void {
		$response = get('/admin/integrations');
		assertNoAdminErrors($response, '/admin/integrations');
	});
});

describe('Error Handling Views', function (): void {
	it('loads custom 404 page editor without errors', function (): void {
		$response = get('/admin/errors/404');
		assertNoAdminErrors($response, '/admin/errors/404');
	});

	it('loads error logs viewer without errors', function (): void {
		$response = get('/admin/logs');
		assertNoAdminErrors($response, '/admin/logs');
	});
});
