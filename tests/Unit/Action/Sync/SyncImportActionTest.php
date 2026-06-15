<?php

namespace Tests\Unit\Action\Sync;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Sync\SyncImportAction;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class SyncImportActionTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $jumpStartImporter;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->jumpStartImporter = $this->createMock(JumpStartImporter::class);
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);
	}

	private function actionWithSession(SessionInterface $session, UserValidationService $userValidation): SyncImportAction
	{
		return new SyncImportAction($this->jumpStartImporter, $this->renderer, $session, $userValidation);
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

		// Anonymous / API-key-only caller: no AUTH_USER in session.
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->willReturn(null);
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->expects($this->never())->method('isSuperAdmin');

		// upsert = true (sync semantics), allowSystemCollections = false.
		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, false)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}

	public function testLoggedInNonSuperAdminSyncDoesNotAuthorizeSystemCollections(): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		// A real, logged-in user who is NOT a super-admin — the likeliest
		// real-world bypass attempt.
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('bob');
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->with('bob')->willReturn(false);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, false)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}

	public function testSuperAdminSyncAuthorizesSystemCollections(): void
	{
		$definition = ['name' => 'Mirror', 'objects' => []];
		$this->stubBody((string)json_encode($definition));

		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('admin');
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->with('admin')->willReturn(true);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, true, true)
			->willReturn(OperationResult::success());

		$this->renderer->method('json')->willReturn($this->response);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}

	public function testThrowsOnEmptyBody(): void
	{
		$this->stubBody('');
		$session        = $this->createMock(SessionInterface::class);
		$userValidation = $this->createMock(UserValidationService::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}

	public function testThrowsOnInvalidJson(): void
	{
		$this->stubBody('not json');
		$session        = $this->createMock(SessionInterface::class);
		$userValidation = $this->createMock(UserValidationService::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}

	public function testThrowsWhenJsonRootIsNotAnObject(): void
	{
		$this->stubBody('"a string"');
		$session        = $this->createMock(SessionInterface::class);
		$userValidation = $this->createMock(UserValidationService::class);

		$this->expectException(HttpBadRequestException::class);

		($this->actionWithSession($session, $userValidation))($this->request, $this->response);
	}
}
