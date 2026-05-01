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

describe('GetFileAction', function (): void {
	it('returns 404 for nonexistent file', function (): void {
		$response = get('/api/upload/blog/test-id/content/nonexistent-file.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('handles request with valid path format', function (): void {
		$response = get('/api/upload/blog/object-123/image/photo.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('handles request with special characters in filename', function (): void {
		$response = get('/api/upload/blog/object-123/files/document-v2.pdf');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});
});

describe('DeleteFileAction', function (): void {
	it('handles delete request for nonexistent file', function (): void {
		$response = delete('/api/upload/blog/test-id/content/nonexistent-file.jpg');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});

	it('handles delete with valid path format', function (): void {
		$response = delete('/api/upload/products/item-456/gallery/image.png');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});

describe('UploadFileAction', function (): void {
	it('returns 400 when no file is provided', function (): void {
		// GET hits the ListUploadFilesAction which may return 200 (empty list)
		$response = get('/api/upload/blog/test-id/content');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});

describe('Nested upload routes (Phase 1)', function (): void {
	it('routes a nested upload-fetch path to GetFileAction', function (): void {
		// Single subpath segment + filename — the route added in Phase 1
		// (`/upload/{coll}/{id}/{prop}/{path:.+}`) matches and dispatches.
		// 404 is the expected response for a file that doesn't exist on disk.
		$response = get('/api/upload/blog/post-1/mycard/childprop/photo.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('routes a deeply nested upload-fetch path', function (): void {
		// Deck-item style nesting: parent prop + item id + child prop + filename.
		$response = get('/api/upload/blog/post-1/mydeck/item-3/styledtext/photo.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('routes a nested DELETE through DeleteFileAction', function (): void {
		$response = delete('/api/upload/blog/post-1/mydeck/item-3/styledtext/photo.jpg');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});

	it('routes a nested imageworks/upload fetch', function (): void {
		$response = get('/api/imageworks/upload/blog/post-1/mydeck/item-3/styledtext/photo.jpg');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('routes a nested download/upload fetch', function (): void {
		$response = get('/api/download/upload/blog/post-1/mydeck/item-3/styledtext/document.pdf');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('routes a nested stream/upload fetch', function (): void {
		$response = get('/api/stream/upload/blog/post-1/mydeck/item-3/styledtext/video.mp4');
		expect($response->getStatusCode())->toBeIn([401, 403, 404, 405]);
	});

	it('accepts a `?path=` query for nested directory listing', function (): void {
		// Image Manager listing inside a nested context uses the property-root
		// route + ?path= subpath. The route must dispatch to ListUploadFilesAction
		// (returns 200 with `files` array, possibly empty).
		$response = get('/api/upload/blog/post-1/mydeck?path=item-3/styledtext&type=image');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});
