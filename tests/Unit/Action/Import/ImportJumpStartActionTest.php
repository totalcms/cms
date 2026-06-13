<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use Odan\Session\SessionInterface;
use TotalCMS\Action\Import\ImportJumpStartAction;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class ImportJumpStartActionTest extends TestCase
{
	private ImportJumpStartAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $jumpStartImporter;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $userValidation;

	protected function setUp(): void
	{
		$this->jumpStartImporter = $this->createMock(JumpStartImporter::class);
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->userValidation    = $this->createMock(UserValidationService::class);

		// Default: anonymous / non-super-admin caller.
		$this->session->method('get')->willReturn(null);

		$this->action = new ImportJumpStartAction(
			$this->jumpStartImporter,
			$this->renderer,
			$this->session,
			$this->userValidation,
		);
	}

	public function testImportsDemoDefinition(): void
	{
		$demoResult = OperationResult::success('Import completed successfully', [
			'collections' => [],
			'schemas'     => [],
		]);

		$this->request->method('getQueryParams')->willReturn(['demo' => 'true']);

		$this->jumpStartImporter->expects($this->once())
			->method('importDemoDefinition')
			->with(false)
			->willReturn($demoResult);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $demoResult->toArray())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}

	public function testImportsFromUploadedFile(): void
	{
		$definition  = ['name' => 'My Project', 'collections' => []];
		$jsonContent = json_encode($definition);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$result = OperationResult::success('Import completed successfully', ['collections' => 5]);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, false, false)
			->willReturn($result);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $result->toArray())
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testSuperAdminCallerAllowsSystemCollections(): void
	{
		$definition  = ['name' => 'My Project', 'collections' => []];
		$jsonContent = json_encode($definition);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		// Super-admin session: get(AUTH_USER) returns an id, isSuperAdmin true.
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('admin');
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->with('admin')->willReturn(true);

		$action = new ImportJumpStartAction($this->jumpStartImporter, $this->renderer, $session, $userValidation);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$result = OperationResult::success('Import completed successfully', ['collections' => 5]);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, false, true)
			->willReturn($result);

		$this->renderer->method('json')->willReturn($this->response);

		($action)($this->request, $this->response);
	}

	public function testLoggedInNonSuperAdminDoesNotAllowSystemCollections(): void
	{
		$definition  = ['name' => 'My Project', 'collections' => []];
		$jsonContent = json_encode($definition);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn($jsonContent);

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		// A real, logged-in user who is NOT a super-admin — the likeliest
		// real-world bypass attempt. Must resolve to allowSystemCollections=false.
		$session = $this->createMock(SessionInterface::class);
		$session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('bob');
		$userValidation = $this->createMock(UserValidationService::class);
		$userValidation->method('isSuperAdmin')->with('bob')->willReturn(false);

		$action = new ImportJumpStartAction($this->jumpStartImporter, $this->renderer, $session, $userValidation);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$result = OperationResult::success('Import completed successfully', ['collections' => 5]);

		$this->jumpStartImporter->expects($this->once())
			->method('importFromDefinition')
			->with($definition, false, false)
			->willReturn($result);

		$this->renderer->method('json')->willReturn($this->response);

		($action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenFileNotUploaded(): void
	{
		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn([]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionWhenUploadHasError(): void
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_NO_FILE);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Upload failed');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForInvalidJson(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn('invalid json');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid JSON format');

		($this->action)($this->request, $this->response);
	}

	public function testThrowsExceptionForNonArrayJson(): void
	{
		$stream = $this->createMock(StreamInterface::class);
		$stream->method('__toString')->willReturn('"string"');

		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getStream')->willReturn($stream);

		$this->request->method('getQueryParams')->willReturn([]);
		$this->request->method('getUploadedFiles')->willReturn(['jumpstart' => $file]);

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid JSON format');

		($this->action)($this->request, $this->response);
	}

	public function testDemoModeUsesImportDemoDefinition(): void
	{
		$this->request->method('getQueryParams')->willReturn(['demo' => 'true']);

		$this->jumpStartImporter->expects($this->once())
			->method('importDemoDefinition')
			->willReturn(OperationResult::success());

		$this->jumpStartImporter->expects($this->never())
			->method('importFromDefinition');

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
