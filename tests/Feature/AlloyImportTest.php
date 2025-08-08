<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

describe('Alloy Import API Validation', function () {
	test('alloy analyze endpoint validates required fields', function () {
		$response = post('/import/alloy-analyze', []);
		
		$response->assertStatus(400)
			->assertJson()
			->assertJsonFragment(['success' => false]);
		
		$data = json_decode($response->getBody()->getContents(), true);
		expect($data['message'])->toContain('Missing required field');
	});

	test('alloy import endpoint validates required fields', function () {
		$response = post('/import/alloy', []);
		
		$response->assertStatus(400)
			->assertJson()
			->assertJsonFragment(['success' => false]);
		
		$data = json_decode($response->getBody()->getContents(), true);
		expect($data['message'])->toContain('Missing required field');
	});
});

describe('Alloy Import Data Processing', function () {
	test('alloy analyze endpoint with test data', function () {
		$response = post('/import/alloy-analyze', [
			'blog' => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds' => 'tests/test-data/alloy/embeds',
			'droplets' => 'tests/test-data/alloy/droplets',
		]);

		$response->assertStatus(200)
			->assertJson()
			->assertJsonFragment(['success' => true])
			->assertJsonFragment(['message' => 'Analysis completed successfully']);
		
		$data = json_decode($response->getBody()->getContents(), true);
		expect($data['data'])->toHaveKeys(['blogs', 'embeds', 'droplets']);
		
		// Verify we found the expected number of items
		expect(count($data['data']['blogs']))->toBeGreaterThan(30);
		expect(count($data['data']['embeds']))->toBeGreaterThan(60);
		expect(count($data['data']['droplets']))->toBeGreaterThan(60);
	});

	test('alloy analyze parses blog metadata correctly', function () {
		$response = post('/import/alloy-analyze', [
			'blog' => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds' => 'tests/test-data/alloy/embeds',
			'droplets' => 'tests/test-data/alloy/droplets',
		]);

		$response->assertStatus(200)->assertJson();
		$data = json_decode($response->getBody()->getContents(), true);
		$blogs = $data['data']['blogs'];
		
		// Find a specific blog post to test
		$websitePost = collect($blogs)->firstWhere('id', 'the-website-is-live');
		expect($websitePost)->not->toBeNull();
		
		// Verify metadata parsing
		expect($websitePost['title'])->toBe('The Website is live!');
		expect($websitePost['author'])->toBe('Adam Jackson');
		expect($websitePost['category'])->toBe('Blog');
		expect($websitePost['date'])->toBe('2021-03-01');
		expect($websitePost['draft'])->toBeFalse();
		expect($websitePost['has_image'])->toBeTrue();
		expect($websitePost['tags'])->toContain('Social Media');
		expect($websitePost['tags'])->toContain('Photography');
	});

	test('alloy analyze handles different droplet types', function () {
		$response = post('/import/alloy-analyze', [
			'blog' => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds' => 'tests/test-data/alloy/embeds',
			'droplets' => 'tests/test-data/alloy/droplets',
		]);

		$response->assertStatus(200)->assertJson();
		$data = json_decode($response->getBody()->getContents(), true);
		$droplets = $data['data']['droplets'];
		
		// Find text and image droplets
		$textDroplet = collect($droplets)->firstWhere('type', 'text');
		$imageDroplet = collect($droplets)->firstWhere('type', 'image');
		
		expect($textDroplet)->not->toBeNull();
		expect($imageDroplet)->not->toBeNull();
		
		expect($textDroplet['type'])->toBe('text');
		expect($textDroplet['data'])->not->toBeEmpty();
		
		expect($imageDroplet['type'])->toBe('image');
		expect($imageDroplet['data'])->toContain('https://');
	});

	test('alloy import endpoint with test data', function () {
		$response = post('/import/alloy', [
			'blog' => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds' => 'tests/test-data/alloy/embeds',
			'droplets' => 'tests/test-data/alloy/droplets',
		]);

		$response->assertStatus(200)
			->assertJson()
			->assertJsonFragment(['success' => true]);
		
		$data = json_decode($response->getBody()->getContents(), true);
		expect($data['message'])->toContain('Successfully queued');
		expect($data['message'])->toContain('items for import from Alloy');
		expect($data)->toHaveKey('import_count');
		expect($data['import_count'])->toBeGreaterThan(150); // Should be around 170 items
	});
});

describe('Alloy Import Error Handling', function () {
	test('alloy import handles missing directories gracefully', function () {
		$response = post('/import/alloy-analyze', [
			'blog' => 'nonexistent/posts',
			'image_uploads' => 'nonexistent/images',
			'embeds' => 'nonexistent/embeds',
			'droplets' => 'nonexistent/droplets',
		]);

		$response->assertStatus(200)
			->assertJson()
			->assertJsonFragment(['success' => true]);
			
		$data = json_decode($response->getBody()->getContents(), true);
		expect(count($data['data']['blogs']))->toBe(0);
		expect(count($data['data']['embeds']))->toBe(0);
		expect(count($data['data']['droplets']))->toBe(0);
	});

	test('alloy import handles partial directory structure', function () {
		$response = post('/import/alloy-analyze', [
			'blog' => 'tests/test-data/alloy/posts',
			'image_uploads' => 'tests/test-data/alloy/image-uploads',
			'embeds' => 'nonexistent/embeds',
			'droplets' => 'nonexistent/droplets',
		]);

		$response->assertStatus(200)
			->assertJson()
			->assertJsonFragment(['success' => true]);
			
		$data = json_decode($response->getBody()->getContents(), true);
		expect(count($data['data']['blogs']))->toBeGreaterThan(30);
		expect(count($data['data']['embeds']))->toBe(0);
		expect(count($data['data']['droplets']))->toBe(0);
	});
});

describe('Alloy Import Admin Interface', function () {
	test('alloy admin utils page includes alloy option', function () {
		// This test verifies the admin interface integration
		$response = get('/admin/utils/project-setup');
		
		$response->assertStatus(200);
		$content = $response->getBody()->getContents();
		
		expect($content)->toContain('Other Supported Import Tools');
		expect($content)->toContain('Alloy');
		expect($content)->toContain('Import from Alloy CMS');
		expect($content)->toContain('utils/import-alloy');
	});

	test('alloy import form page loads', function () {
		$response = get('/admin/utils/import-alloy');
		
		$response->assertStatus(200);
		$content = $response->getBody()->getContents();
		
		expect($content)->toContain('Import from Alloy');
		expect($content)->toContain('Blog Posts Folder');
		expect($content)->toContain('Image Uploads Folder');
		expect($content)->toContain('Embeds Folder');
		expect($content)->toContain('Droplets Folder');
		expect($content)->toContain('Analyze Alloy Data');
	});
});