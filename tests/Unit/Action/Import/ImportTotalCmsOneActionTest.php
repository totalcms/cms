<?php

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Import\ImportTotalCmsOneAction;
use TotalCMS\Domain\Import\TotalCmsOneImporter;
use TotalCMS\Renderer\JsonRenderer;

final class ImportTotalCmsOneActionTest extends TestCase
{
	private ImportTotalCmsOneAction $action;
	private TotalCmsOneImporter $importer;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;
	private string $testDataPath;
	private string $originalDocRoot;

	protected function setUp(): void
	{
		$this->importer = $this->createMock(TotalCmsOneImporter::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new ImportTotalCmsOneAction($this->importer, $this->renderer);

		// Create test directory
		$this->testDataPath = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		mkdir($this->testDataPath . '/cms-data', 0777, true);

		// Save and set DOCUMENT_ROOT
		$this->originalDocRoot           = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$_SERVER['DOCUMENT_ROOT'] = $this->testDataPath;
	}

	protected function tearDown(): void
	{
		// Restore DOCUMENT_ROOT
		$_SERVER['DOCUMENT_ROOT'] = $this->originalDocRoot;

		// Clean up test directory
		if (is_dir($this->testDataPath)) {
			$this->removeDirectory($this->testDataPath);
		}
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir) ?: [], ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->removeDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}

	public function testImportsFromDefaultPath(): void
	{
		$this->request->method('getParsedBody')->willReturn([]);

		$this->importer->expects($this->once())
			->method('import')
			->with($this->testDataPath . '/cms-data')
			->willReturn(50);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return $data['success'] === true
					&& $data['import_count'] === 50
					&& str_contains($data['message'], '50 items');
			}))
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}

	public function testImportsFromCustomPath(): void
	{
		$customPath = $this->testDataPath . '/custom-cms-data';
		mkdir($customPath, 0777, true);

		$this->request->method('getParsedBody')->willReturn(['path' => $customPath]);

		$this->importer->expects($this->once())
			->method('import')
			->with($customPath)
			->willReturn(25);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturnsErrorWhenPathDoesNotExist(): void
	{
		// Remove the cms-data directory
		rmdir($this->testDataPath . '/cms-data');

		$this->request->method('getParsedBody')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return $data['success'] === false
					&& str_contains($data['message'], 'No cms-data folder found');
			}), 400)
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testHandlesImportException(): void
	{
		$this->request->method('getParsedBody')->willReturn([]);

		$this->importer->method('import')
			->willThrowException(new \Exception('Import error occurred'));

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return $data['success'] === false
					&& str_contains($data['message'], 'Import failed')
					&& str_contains($data['message'], 'Import error occurred');
			}), 500)
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testReturnsSuccessResponse(): void
	{
		$this->request->method('getParsedBody')->willReturn([]);

		$this->importer->method('import')->willReturn(100);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(function ($data) {
				return isset($data['success'])
					&& isset($data['message'])
					&& isset($data['import_count'])
					&& $data['success'] === true
					&& $data['import_count'] === 100;
			}))
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}
}
