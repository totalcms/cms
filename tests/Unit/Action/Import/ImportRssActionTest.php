<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Import;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Import\ImportRssAction;
use TotalCMS\Action\Import\ImportRssAnalyzeAction;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Renderer\JsonRenderer;

// The import and analyze actions parse the same request. They are separate
// classes, so a validation rule added to one can silently miss the other —
// which is how the URL check landed only on analyze. Both are pinned here.
final class ImportRssActionTest extends TestCase
{
	/** @return iterable<string, array{class-string}> */
	public static function actions(): iterable
	{
		yield 'import'  => [ImportRssAction::class];
		yield 'analyze' => [ImportRssAnalyzeAction::class];
	}

	/**
	 * @dataProvider actions
	 *
	 * @param class-string $actionClass
	 */
	public function testRejectsAMalformedUrlBeforeTouchingTheImporter(string $actionClass): void
	{
		$importer = $this->createMock(RssImporter::class);
		$importer->expects($this->never())->method('import');
		$importer->expects($this->never())->method('analyze');

		$renderer = $this->createMock(JsonRenderer::class);
		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$request->method('getParsedBody')->willReturn(['url' => 'not a url', 'collection' => 'blog']);

		$renderer->expects($this->once())
			->method('json')
			->with(
				$response,
				$this->callback(fn (array $data): bool => $data['success'] === false && str_contains((string)$data['message'], 'Invalid URL')),
				400,
			)
			->willReturn($response);

		$action = new $actionClass($importer, $renderer);

		$this->assertSame($response, $action($request, $response));
	}

	/**
	 * @dataProvider actions
	 *
	 * @param class-string $actionClass
	 */
	public function testRejectsAMissingUrl(string $actionClass): void
	{
		$importer = $this->createMock(RssImporter::class);
		$renderer = $this->createMock(JsonRenderer::class);
		$request  = $this->createMock(ServerRequestInterface::class);
		$response = $this->createMock(ResponseInterface::class);

		$request->method('getParsedBody')->willReturn(['collection' => 'blog']);

		$renderer->expects($this->once())
			->method('json')
			->with(
				$response,
				$this->callback(fn (array $data): bool => $data['success'] === false && str_contains((string)$data['message'], 'url')),
				400,
			)
			->willReturn($response);

		$action = new $actionClass($importer, $renderer);

		$this->assertSame($response, $action($request, $response));
	}
}
