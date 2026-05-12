<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Builder;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderPreviewService;
use TotalCMS\Renderer\RawRenderer;

/**
 * POST /admin/builder/preview — render a Site Builder template preview.
 *
 * The editor JS posts the in-progress template content (plus optional URL
 * + page id) and gets back rendered HTML for the preview iframe.
 */
readonly class BuilderPreviewAction
{
	public function __construct(
		private BuilderPreviewService $previewService,
		private RawRenderer $rawRenderer,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$post = (array)$request->getParsedBody();

		$html = $this->previewService->render(
			(string)($post['path'] ?? ''),
			(string)($post['template'] ?? $post['content'] ?? ''),
			(string)($post['previewUrl'] ?? ''),
			(string)($post['pageId'] ?? ''),
		);

		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}
}
