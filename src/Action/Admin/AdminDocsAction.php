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

		// Allow alphanumeric, hyphens, underscores, forward slashes, and dots
		// (dots are needed for image filenames like `wizard.png` — path traversal
		// via `..` was already rejected above).
		if ($page !== '' && !preg_match('#^[a-zA-Z0-9_/.-]+$#', $page)) {
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

		// Serve image files co-located with docs (e.g., screenshots in
		// get-started/images/). The page param carries the full filename
		// including extension when an image is requested.
		$imageMimes = [
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'svg'  => 'image/svg+xml',
			'webp' => 'image/webp',
		];
		$pageExt = strtolower(pathinfo($page, PATHINFO_EXTENSION));
		if (isset($imageMimes[$pageExt])) {
			$imageFile = "{$docsDir}/{$page}";
			if (file_exists($imageFile)) {
				$contents = file_get_contents($imageFile);
				$response->getBody()->write($contents !== false ? $contents : '');

				return $response
					->withHeader('Content-Type', $imageMimes[$pageExt])
					->withHeader('Cache-Control', 'public, max-age=3600');
			}
			// Image not found — fall through to 404 below
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
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

			$data    = $document->getData();
			$html    = $parsedown->text($document->getContent());
			[$html, $toc] = $this->injectHeadingAnchorsAndBuildToc($html);

			$data['content'] = $html;
			$data['toc']     = $toc;
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
		$data['menu'] = $this->loadMenu();

		return $this->twigRenderer->template($response, 'admin/docs.twig', $data);
	}

	/**
	 * Load the docs menu structure from resources/docs/menu.php.
	 * The same file is consumed by bin/build-docs-index.php so search results
	 * carry the correct group label.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function loadMenu(): array
	{
		$menuFile = __DIR__ . '/../../../resources/docs/menu.php';
		if (!file_exists($menuFile)) {
			return [];
		}
		$menu = require $menuFile;
		if (!is_array($menu)) {
			return [];
		}

		$normalized = [];
		foreach ($menu as $group) {
			if (!is_array($group)) {
				continue;
			}
			$entry = [];
			foreach ($group as $key => $value) {
				if (is_string($key)) {
					$entry[$key] = $value;
				}
			}
			$normalized[] = $entry;
		}

		return $normalized;
	}

	/**
	 * Inject id attributes on h2/h3 elements and return a flat TOC array.
	 * Skips headings that already carry an id (e.g. ParsedownExtra {#custom-id} syntax).
	 *
	 * @return array{0:string,1:list<array{level:int,id:string,text:string}>}
	 */
	private function injectHeadingAnchorsAndBuildToc(string $html): array
	{
		$toc      = [];
		$usedIds  = [];
		$pattern  = '/<h([23])(\s[^>]*)?>(.*?)<\/h\1>/i';
		$replaced = preg_replace_callback($pattern, function (array $m) use (&$toc, &$usedIds): string {
			$level   = (int)$m[1];
			$attrs   = $m[2];
			$inner   = $m[3];
			$text    = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5));

			if (preg_match('/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $idMatch)) {
				$id = $idMatch[1];
			} else {
				$id = $this->slugify($text);
				if ($id === '') {
					return $m[0];
				}
				$base = $id;
				$n    = 2;
				while (in_array($id, $usedIds, true)) {
					$id = $base . '-' . $n++;
				}
				$attrs = ' id="' . htmlspecialchars($id, ENT_QUOTES) . '"' . $attrs;
			}

			$usedIds[] = $id;
			$toc[]     = ['level' => $level, 'id' => $id, 'text' => $text];

			return '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
		}, $html);

		return [(string)$replaced, $toc];
	}

	private function slugify(string $text): string
	{
		$text = strtolower($text);
		$text = (string)preg_replace('/[^a-z0-9]+/', '-', $text);

		return trim($text, '-');
	}
}
