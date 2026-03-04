<?php

use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\delete;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Helper to create a template with designer enabled.
 *
 * @return array{id: string, token: string}
 */
function createDesignerTemplate(string $id = 'designer-test', string $content = '<h1>Hello</h1>'): array
{
	$token = 'test-token-' . uniqid();

	postJson('/templates', [
		'id'              => $id,
		'template'        => $content,
		'designerEnabled' => true,
		'designerToken'   => $token,
	])->assertOk();

	return ['id' => $id, 'token' => $token];
}

// --- Designer Metadata via Template API ---

describe('Designer metadata via template API', function (): void {
	it('saves designer metadata with a new template', function (): void {
		$info = createDesignerTemplate('meta-test');

		$this->assertFileExists(templatePath('meta-test'));
		$this->assertFileExists(designerMetaPath('meta-test'));

		$meta = json_decode((string)file_get_contents(designerMetaPath('meta-test')), true);
		expect($meta['designerEnabled'])->toBeTrue();
		expect($meta['designerToken'])->toBe($info['token']);

		delete('/templates/meta-test')->assertOk();
	});

	it('deletes designer metadata when template is deleted', function (): void {
		createDesignerTemplate('delete-meta-test');

		$this->assertFileExists(designerMetaPath('delete-meta-test'));

		delete('/templates/delete-meta-test')->assertOk();

		$this->assertFileDoesNotExist(templatePath('delete-meta-test'));
		$this->assertFileDoesNotExist(designerMetaPath('delete-meta-test'));
	});

	it('saves template without designer metadata when not provided', function (): void {
		postJson('/templates', [
			'id'       => 'no-designer',
			'template' => '<p>Plain</p>',
		])->assertOk();

		$this->assertFileExists(templatePath('no-designer'));
		$this->assertFileDoesNotExist(designerMetaPath('no-designer'));

		delete('/templates/no-designer')->assertOk();
	});
});

// --- Designer API Endpoints ---

describe('Designer API - PUT', function (): void {
	it('updates template content with valid token', function (): void {
		$info = createDesignerTemplate('put-test', '<h1>Original</h1>');

		// Use put() helper with designer token header and template in form data
		$response = $this->put('/designer/templates/put-test', [
			'template' => '<h1>Updated via Designer</h1>',
		], [
			'X-Designer-Token' => $info['token'],
		]);
		expect($response->getStatusCode())->toBe(200);

		// Verify the template content was updated
		$content = file_get_contents(templatePath('put-test'));
		expect($content)->toBe('<h1>Updated via Designer</h1>');

		// Verify designer metadata is untouched
		$meta = json_decode((string)file_get_contents(designerMetaPath('put-test')), true);
		expect($meta['designerEnabled'])->toBeTrue();
		expect($meta['designerToken'])->toBe($info['token']);

		delete('/templates/put-test')->assertOk();
	});

	it('returns 401 with invalid token on PUT', function (): void {
		$info = createDesignerTemplate('put-auth-test');

		$response = $this->put('/designer/templates/put-auth-test', [
			'template' => 'new content',
		], [
			'X-Designer-Token' => 'wrong-token',
		]);
		expect($response->getStatusCode())->toBe(401);

		delete('/templates/put-auth-test')->assertOk();
	});

	it('returns 404 for non-existent template on PUT', function (): void {
		$response = $this->put('/designer/templates/nonexistent', [
			'template' => 'content',
		], [
			'X-Designer-Token' => 'any-token',
		]);
		expect($response->getStatusCode())->toBe(404);
	});

	it('supports token via query parameter', function (): void {
		$info = createDesignerTemplate('query-token-test');

		$response = $this->put('/designer/templates/query-token-test?token=' . $info['token'], [
			'template' => '<p>Updated via query token</p>',
		]);
		expect($response->getStatusCode())->toBe(200);

		delete('/templates/query-token-test')->assertOk();
	});

	it('updates template in a folder', function (): void {
		$token = 'folder-token-' . uniqid();

		postJson('/templates', [
			'id'              => 'grids/card',
			'template'        => '<div>Card</div>',
			'designerEnabled' => true,
			'designerToken'   => $token,
		])->assertOk();

		$response = $this->put('/designer/templates/grids/card', [
			'template' => '<div>Updated Card</div>',
		], [
			'X-Designer-Token' => $token,
		]);
		expect($response->getStatusCode())->toBe(200);

		$content = file_get_contents(templatePath('card', 'grids'));
		expect($content)->toBe('<div>Updated Card</div>');

		delete('/templates/grids/card')->assertOk();
	});
});
