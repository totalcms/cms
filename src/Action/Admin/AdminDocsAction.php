<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use Webuni\FrontMatter\FrontMatterChain;

/**
 * Action.
 */
readonly class AdminDocsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$page = $args['page'] ?? 'index';

		// Prevent path traversal attacks by sanitizing the page parameter
		// Allow forward slashes for subdirectories but prevent ../ attacks
		$page = str_replace('\\', '/', $page); // Normalize path separators
		$page = (string)preg_replace('#/+#', '/', $page); // Remove duplicate slashes
		$page = trim($page, '/'); // Remove leading/trailing slashes

		// Prevent directory traversal
		if (str_contains($page, '..')) {
			$page = 'index';
		}

		// Allow alphanumeric, hyphens, underscores, and forward slashes
		if ($page !== '' && !preg_match('#^[a-zA-Z0-9_/-]+$#', $page)) {
			$page = 'index';
		}

		// Validate that the requested documentation page exists
		$docsDir      = __DIR__ . '/../../../resources/docs';
		$htmlFile     = "{$docsDir}/{$page}.html";
		$markdownFile = "{$docsDir}/{$page}.md";
		$jsonFile     = "{$docsDir}/{$page}.json";

		// Serve JSON files directly (e.g., search-index.json)
		if (file_exists($jsonFile)) {
			$jsonContents = file_get_contents($jsonFile);
			$response->getBody()->write($jsonContents !== false ? $jsonContents : '{}');

			return $response->withHeader('Content-Type', 'application/json');
		}

		// If neither file exists, return 404
		if (!file_exists($htmlFile) && !file_exists($markdownFile)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		$data = [];

		if (file_exists($markdownFile)) {
			$contents = file_get_contents($markdownFile);
			if (!$contents) {
				throw new \UnexpectedValueException("Unable to read Doc Page $page");
			}

			$parsedown   = new \ParsedownExtra();
			$frontMatter = FrontMatterChain::create();
			$document    = $frontMatter->parse($contents);

			$data            = $document->getData();
			$data['content'] = $parsedown->text($document->getContent());
		} elseif (file_exists($htmlFile)) {
			$htmlContents    = file_get_contents($htmlFile);
			$data['content'] = $htmlContents !== false ? $htmlContents : 'Unable to read page';
		}

		$data['page'] = $page;
		$data['url']  = [
			'path'   => $request->getUri()->getPath(),
			'query'  => $request->getUri()->getQuery(),
			'params' => $args,
			'page'   => 'docs',
		];

		return $this->twigRenderer->template($response, 'admin/docs.twig', $data);
	}
}
