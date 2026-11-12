<?php

namespace Tests\Unit\Action\Sync;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Sync\SyncImportAction;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class SyncImportActionTest extends TestCase
{
	private MockObject $jumpStartImporter;
	private MockObject $renderer;
	private MockObject $request;
	private MockObject $response;

	protected function setUp(): void
	{
		$this->jumpStartImporter = $this->createMock(JumpStartImporter::class);
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);
	}

	private function actionWithAccess(AccessManager $access): SyncImportAction
	{
		return new SyncImportAction($this->jumpStartImporter, $this->renderer, $access);
	}

	private function stubBody(string $json): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($json);
		$this->request->method('getBody')->willReturn($stream);
	}

	public function testNonSuperAdminSyncDoesNotAuthorizeSystemCollections(): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		// Anonymous caller: no AUTH_USER in session and no API key.
		$access = $this->createMock(AccessManager::class);
		$access->method('sessionIsSuperAdmin')->willReturn(false);

		// upsert = true (sync semantics), allowSystemCollections = false.
		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, false)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	public function testLoggedInNonSuperAdminSyncDoesNotAuthorizeSystemCollections(): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		// A real, logged-in user who is NOT a super-admin — the likeliest
		// real-world bypass attempt.
		$access = $this->createMock(AccessManager::class);
		$access->method('sessionIsSuperAdmin')->willReturn(false);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, false)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	public function testSuperAdminSyncAuthorizesSystemCollections(): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		$access = $this->createMock(AccessManager::class);
		$access->method('sessionIsSuperAdmin')->willReturn(true);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, true)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	/**
	 * A key with the Sync Manager permission was made by a super-admin (only
	 * they can create keys) for exactly this, so it may push everything sync
	 * moves, automations included.
	 *
	 * @return array<string,array{list<string>,bool}>
	 */
	public static function syncKeys(): array
	{
		return [
			'Sync Manager permission' => [['/sync'], true],
			'all endpoints'           => [['*'], true],
			'other endpoints only'    => [['/collections', '/import'], false],
		];
	}

	/**
	 * @param list<string> $paths
	 *
	 * @dataProvider syncKeys
	 */
	public function testApiKeyWithSyncPermissionAuthorizesSystemCollections(array $paths, bool $allowed): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		$key = new ApiKeyData(['id' => 'k1', 'name' => 'Sync', 'key' => 'x', 'created' => '', 'scopes' => ['methods' => ['GET', 'POST'], 'paths' => $paths]]);
		$this->request->method('getAttribute')->willReturnCallback(fn (string $name): mixed => match ($name) {
			'authMethod' => 'apikey',
			'apiKey'     => $key,
			default      => null,
		});

		$access = $this->createMock(AccessManager::class);
		$access->method('sessionIsSuperAdmin')->willReturn(false);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, $allowed)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	public function testThrowsOnEmptyBody(): void
	{
		$this->stubBody('');
		$access = $this->createMock(AccessManager::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	public function testThrowsOnInvalidJson(): void
	{
		$this->stubBody('not json');
		$access = $this->createMock(AccessManager::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithAccess($access))($this->request, $this->response);
	}

	public function testThrowsWhenJsonRootIsNotAnObject(): void
	{
		$this->stubBody('"a string"');
		$access = $this->createMock(AccessManager::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithAccess($access))($this->request, $this->response);
	}
}
