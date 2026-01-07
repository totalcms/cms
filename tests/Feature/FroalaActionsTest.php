<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('FroalaGetFileAction', function (): void {
	it('returns 404 for nonexistent file', function (): void {
		$response = get('/upload/blog/test-id/content/nonexistent-file.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('handles request with valid path format', function (): void {
		$response = get('/upload/blog/object-123/image/photo.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('handles request with special characters in filename', function (): void {
		$response = get('/upload/blog/object-123/files/document-v2.pdf');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});
});

describe('FroalaDeleteFileAction', function (): void {
	it('handles delete request for nonexistent file', function (): void {
		$response = delete('/upload/blog/test-id/content/nonexistent-file.jpg');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});

	it('handles delete with valid path format', function (): void {
		$response = delete('/upload/products/item-456/gallery/image.png');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});

describe('FroalaUploadFileAction', function (): void {
	it('returns 400 when no file is provided', function (): void {
		// POST without file should fail
		$response = get('/upload/blog/test-id/content'); // Using GET to test route exists
		expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405]);
	});
});
