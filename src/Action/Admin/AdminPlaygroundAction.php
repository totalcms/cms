<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminPlaygroundAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
		private RawRenderer $rawRenderer,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// Handle HTMX POST requests - return only the results fragment
		if ($request->getMethod() === 'POST' && $request->getHeaderLine('HX-Request') === 'true') {
			return $this->renderResultsFragment($request, $response);
		}

		// Handle GET requests for the playground page
		return $this->twigRenderer->template($response, 'admin/playground.twig', [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'page'       => 'playground',
				'id'         => $args['id'] ?? '',
				'collection' => 'playground',
			],
		]);
	}

	private function renderResultsFragment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$post = (array)$request->getParsedBody();
		$render = '';

		if (isset($post['twig'])) {
			try {
				$render = $this->twigEngine->renderString($post['twig']);
			} catch (\Throwable $e) {
				$render = '<div class="cms-twig-error"><strong>Error:</strong> <pre>' .
					htmlspecialchars($e->getMessage()) .
					'</pre></div>';
			}
		}

		$html = $this->buildResultsFragment($render);
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $html);
	}

	private function buildResultsFragment(string $results): string
	{
		if ($results === '') {
			return '';
		}

		$escaped = htmlspecialchars($results);

		return <<<HTML
		<h2>Results</h2>
		<div class="result-section">
			<h3>Rendered HTML</h3>
			<div class="rendered-output">{$results}</div>
		</div>
		<div class="result-section">
			<h3>HTML</h3>
			<textarea id="html-output">{$escaped}</textarea>
		</div>
		HTML;
	}
}
