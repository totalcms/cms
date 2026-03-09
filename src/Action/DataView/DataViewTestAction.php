<?php

declare(strict_types=1);

namespace TotalCMS\Action\DataView;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Renderer\JsonRenderer;

readonly class DataViewTestAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private DataViewBuilder $viewBuilder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data       = (array)$request->getParsedBody();
		$definition = (string)($data['definition'] ?? '');

		if ($definition === '') {
			return $this->renderer->json($response, ['error' => 'Definition is required'])->withStatus(400);
		}

		$result = $this->viewBuilder->testView($definition);

		return $this->renderer->json($response, $result);
	}
}
