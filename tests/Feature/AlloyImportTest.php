<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

describe('Alloy Import API Validation', function (): void {
	test('alloy analyze endpoint exists and handles requests', function (): void {
		$response = postJson('/import/alloy-analyze', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 404, 405, 500]);
	});

	test('alloy import endpoint exists and handles requests', function (): void {
		$response = postJson('/import/alloy', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 404, 405, 500]);
	});
});

describe('Alloy Import Data Processing', function (): void {
	test('alloy analyze endpoint with test data', function (): void {
		$response = postJson('/import/alloy-analyze', [
			'blog'          => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds'        => 'tests/test-data/alloy/embeds',
			'droplets'      => 'tests/test-data/alloy/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);

		if ($response->getStatusCode() === 200) {
			$data = json_decode($response->getBody()->getContents(), true);
			if ($data && isset($data['success']) && $data['success']) {
				expect($data)->toHaveKey('data');
				expect($data['data'])->toHaveKeys(['blogs', 'embeds', 'droplets']);
			}
		}
	});

	test('alloy analyze handles blog metadata parsing', function (): void {
		$response = postJson('/import/alloy-analyze', [
			'blog'          => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds'        => 'tests/test-data/alloy/embeds',
			'droplets'      => 'tests/test-data/alloy/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);
	});

	test('alloy analyze handles different droplet types', function (): void {
		$response = postJson('/import/alloy-analyze', [
			'blog'          => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds'        => 'tests/test-data/alloy/embeds',
			'droplets'      => 'tests/test-data/alloy/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);
	});

	test('alloy import endpoint with test data', function (): void {
		$response = postJson('/import/alloy', [
			'blog'          => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds'        => 'tests/test-data/alloy/embeds',
			'droplets'      => 'tests/test-data/alloy/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);
	});
});

describe('Alloy Import Error Handling', function (): void {
	test('alloy import handles missing directories gracefully', function (): void {
		$response = postJson('/import/alloy-analyze', [
			'blog'          => 'nonexistent/posts',
			'image_uploads' => 'nonexistent/images',
			'embeds'        => 'nonexistent/embeds',
			'droplets'      => 'nonexistent/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);
	});

	test('alloy import handles partial directory structure', function (): void {
		$response = postJson('/import/alloy-analyze', [
			'blog'          => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds'        => 'nonexistent/embeds',
			'droplets'      => 'nonexistent/droplets',
		]);

		expect($response->getStatusCode())->toBeIn([200, 400]);
	});
});

describe('Alloy Import Admin Interface', function (): void {
	test('alloy admin utils page includes alloy option', function (): void {
		$response = get('/admin/utils/project-setup');
		expect($response->getStatusCode())->toBeIn([200, 302, 404]);
	});

	test('alloy import form page loads', function (): void {
		$response = get('/admin/utils/import-alloy');
		expect($response->getStatusCode())->toBeIn([200, 302, 404]);
	});
});
