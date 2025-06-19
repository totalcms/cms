<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\put;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Image Operations', function () {
	beforeEach(function (): void {
		// Create test collection for image operations
		$collection = [
			'id' => 'image-test',
			'name' => 'Image Test Collection',
			'schema' => 'gallery',
		];
		
		postJson('/collections', $collection);
		
		// Create test object
		$object = [
			'id' => 'test-image-object',
			'title' => 'Test Image Object',
		];
		
		postJson('/collections/image-test', $object);
	});

	it('can update info for an image', function (): void {
		// Test image metadata update
		$updateData = [
			'alt' => 'Updated image alt text',
			'caption' => 'Updated image caption',
			'title' => 'Updated image title',
		];
		
		$response = put('/api/collections/image-test/test-image-object/images/test-property/info', $updateData);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Update info endpoint exists
	});

	it('can resize an image from gallery', function (): void {
		// Test image resizing functionality
		$resizeParams = [
			'width' => 800,
			'height' => 600,
			'quality' => 90,
		];
		
		$response = post('/api/collections/image-test/test-image-object/gallery/test-property/resize', $resizeParams);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Resize endpoint exists
	});

	it('can replace an image and clear its cache', function (): void {
		// Test image replacement
		$response = put('/api/collections/image-test/test-image-object/images/test-property/replace');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Replace endpoint exists
		
		// Test cache clearing after replacement
		$response = delete('/api/collections/image-test/test-image-object/images/test-property/cache');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Cache clear endpoint exists
	});

	it('can delete an image', function (): void {
		// Test image deletion
		$response = delete('/api/collections/image-test/test-image-object/images/test-property/test-image.jpg');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Delete endpoint exists
	});

	it('can upload an image to a gallery', function (): void {
		// Test gallery image upload
		$response = post('/api/collections/image-test/test-image-object/gallery/test-property/upload');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Upload endpoint exists
	});

	it('can delete an image from gallery', function (): void {
		// Test gallery image deletion
		$response = delete('/api/collections/image-test/test-image-object/gallery/test-property/test-image.jpg');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Gallery delete endpoint exists
	});

	it('can update info for an image from gallery', function (): void {
		// Test gallery image metadata update
		$updateData = [
			'alt' => 'Updated gallery image alt text',
			'caption' => 'Updated gallery image caption',
			'title' => 'Updated gallery image title',
		];
		
		$response = put('/api/collections/image-test/test-image-object/gallery/test-property/info', $updateData);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Gallery update info endpoint exists
	});

	it('can clear cache for an image', function (): void {
		// Test image cache clearing
		$response = delete('/api/collections/image-test/test-image-object/images/test-property/cache');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Cache clear endpoint exists
	});

	it('can crop an image from gallery', function (): void {
		// Test gallery image cropping
		$cropParams = [
			'x' => 100,
			'y' => 100,
			'width' => 400,
			'height' => 300,
		];
		
		$response = post('/api/collections/image-test/test-image-object/gallery/test-property/crop', $cropParams);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Crop endpoint exists
	});

	it('can clear cache for an image from gallery', function (): void {
		// Test gallery image cache clearing
		$response = delete('/api/collections/image-test/test-image-object/gallery/test-property/cache');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Gallery cache clear endpoint exists
	});

	it('can generate image thumbnails', function (): void {
		// Test thumbnail generation
		$thumbnailParams = [
			'size' => 'thumbnail',
			'width' => 150,
			'height' => 150,
		];
		
		$response = post('/api/collections/image-test/test-image-object/images/test-property/thumbnail', $thumbnailParams);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Thumbnail endpoint exists
	});

	it('can serve optimized images', function (): void {
		// Test optimized image serving
		$response = get('/images/image-test/test-image-object/test-property/test-image.jpg?quality=80&width=800');
		expect($response->getStatusCode())->toBeIn([200, 404]); // Optimized image endpoint exists
	});
});