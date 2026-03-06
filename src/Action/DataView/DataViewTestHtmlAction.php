<?php

declare(strict_types=1);

namespace TotalCMS\Action\DataView;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Renderer\RawRenderer;

readonly class DataViewTestHtmlAction
{
	public function __construct(
		private RawRenderer $renderer,
		private DataViewBuilder $viewBuilder,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data       = (array)$request->getParsedBody();
		$definition = (string)($data['definition'] ?? '');

		if ($definition === '') {
			$html = HTMLUtils::element('div', 'Definition is required', ['class' => 'test-error']);

			return $this->renderer->render($response, $html);
		}

		$result = $this->viewBuilder->testView($definition);

		if ($result['success']) {
			$json = htmlspecialchars(json_encode($result['data'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
			$html = HTMLUtils::element('pre', $json, ['class' => 'test-success']);
		} else {
			$html = HTMLUtils::element('div', htmlspecialchars((string)($result['error'] ?? 'Unknown error')), ['class' => 'test-error']);
		}

		return $this->renderer->render($response, $html);
	}
}
