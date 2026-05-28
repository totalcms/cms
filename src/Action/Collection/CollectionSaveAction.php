<?php

declare(strict_types=1);

namespace TotalCMS\Action\Collection;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

readonly class CollectionSaveAction
{
	use ValidatesMcpToolsTrait;

	public function __construct(
		private JsonRenderer $renderer,
		private CollectionSaver $service,
		private McpToolsValidator $mcpToolsValidator,
		private TranslationService $translator,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		// POST body carries the collection ID — no route param available.
		$collectionId = (string)($data['id'] ?? '');

		// Validate mcp.tools before persisting.
		$meta = $this->validateMcpTools($request, $collectionId, $data, $this->mcpToolsValidator, $this->translator);

		$collection = $this->service->saveCollection($data);

		return $this->renderer->jsonItem($response, $collection, new CollectionMetaTransformer(), $meta);
	}
}
