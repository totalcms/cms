<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TotalCMS\Action\Import\ImportAlloyAction;
use TotalCMS\Action\Import\ImportAlloyAnalyzeAction;
use TotalCMS\Action\Import\ImportDeckCsvAction;
use TotalCMS\Action\Import\ImportDeckJsonAction;
use TotalCMS\Action\Import\ImportRssAction;
use TotalCMS\Action\Import\ImportRssAnalyzeAction;
use TotalCMS\Action\Import\ImportWordpressAction;
use TotalCMS\Action\Import\ImportWordpressAnalyzeAction;
use TotalCMS\Domain\Import\AlloyImporter;
use TotalCMS\Domain\Import\DeckCsvImporter;
use TotalCMS\Domain\Import\DeckJsonImporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Each analyze action extends its import action so the two parse one request:
 * a validation rule added to the import path cannot miss the analyze path
 * again, which is how the RSS URL check once landed on only one of them.
 */
final class ImportActionPairsTest extends TestCase
{
	public function testEveryAnalyzeActionIsItsImportAction(): void
	{
		$this->assertTrue(is_subclass_of(ImportAlloyAnalyzeAction::class, ImportAlloyAction::class));
		$this->assertTrue(is_subclass_of(ImportWordpressAnalyzeAction::class, ImportWordpressAction::class));
		$this->assertTrue(is_subclass_of(ImportRssAnalyzeAction::class, ImportRssAction::class));
	}

	/** @return iterable<string, array{class-string}> */
	public static function alloyActions(): iterable
	{
		yield 'import'  => [ImportAlloyAction::class];
		yield 'analyze' => [ImportAlloyAnalyzeAction::class];
	}

	/**
	 * @dataProvider alloyActions
	 *
	 * @param class-string $actionClass
	 */
	public function testAMissingAlloyFolderIs400OnBothPaths(string $actionClass): void
	{
		$importer = $this->createMock(AlloyImporter::class);
		$importer->expects($this->never())->method('import');
		$importer->expects($this->never())->method('analyze');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['blog' => '/b', 'image_uploads' => '/i', 'embeds' => '/e']);
		$response = $this->createMock(ResponseInterface::class);

		$renderer = $this->createMock(JsonRenderer::class);
		$renderer->expects($this->once())->method('json')
			->with($response, ['success' => false, 'message' => 'Missing required field: droplets'], 400)
			->willReturn($response);

		$action = new $actionClass($importer, $renderer);
		$action($request, $response);
	}

	/** @return iterable<string, array{class-string, class-string}> */
	public static function deckActions(): iterable
	{
		yield 'csv'  => [ImportDeckCsvAction::class, DeckCsvImporter::class];
		yield 'json' => [ImportDeckJsonAction::class, DeckJsonImporter::class];
	}

	/**
	 * @dataProvider deckActions
	 *
	 * @param class-string $actionClass
	 * @param class-string $importerClass
	 */
	public function testADeckImportWithoutObjectAndPropertyIsRejected(string $actionClass, string $importerClass): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn(['object' => 'post-1']);

		$action = new $actionClass($this->createMock($importerClass), $this->createMock(JsonRenderer::class));

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Object and property are required');
		$action($request, $this->createMock(ResponseInterface::class), ['collection' => 'blog']);
	}
}
