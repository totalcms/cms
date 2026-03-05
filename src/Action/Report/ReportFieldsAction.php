<?php

declare(strict_types=1);

namespace TotalCMS\Action\Report;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Report\Service\ReportFieldResolver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\RawRenderer;

/**
 * Returns available report fields for a collection.
 *
 * Supports two formats:
 * - json: Returns structured field data
 * - html (default): Returns checkbox HTML for HTMX injection
 */
readonly class ReportFieldsAction
{
	public function __construct(
		private ReportFieldResolver $fieldResolver,
		private JsonRenderer $jsonRenderer,
		private RawRenderer $rawRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$format     = $request->getQueryParams()['format'] ?? 'html';

		if ($format === 'json') {
			return $this->jsonRenderer->json($response, $this->fieldResolver->resolve($collection));
		}

		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $this->fieldResolver->renderHtml($collection));
	}
}
