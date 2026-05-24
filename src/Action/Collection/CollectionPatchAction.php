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

readonly class CollectionPatchAction
{
	use ValidatesMcpToolsTrait;

	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param CollectionSaver $service Collection save service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private CollectionSaver $service,
		private McpToolsValidator $mcpToolsValidator,
		private TranslationService $translator,
	) {
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		// Validate mcp.tools before persisting (if the patch body includes mcp.tools).
		$meta = $this->validateMcpTools($request, $args['collection'], $data, $this->mcpToolsValidator, $this->translator);

		return $this->renderer->jsonItem(
			$response,
			$this->service->patchCollection($args['collection'], $data),
			new CollectionMetaTransformer(),
			$meta,
		);
	}
}
