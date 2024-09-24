<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use Webuni\FrontMatter\FrontMatterChain;

/**
 * Action.
 */
final class AdminDocsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/**
	 * @SuppressWarnings(PHPMD.ElseExpression)
	 *
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$page = $args['page'] ?? 'index';

		$data = [];

		$htmlFile     = __DIR__ . "/../../../resources/docs/{$page}.html";
		$markdownFile = __DIR__ . "/../../../resources/docs/{$page}.md";

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
			$data['content'] = file_get_contents($htmlFile);
		} else {
			$data['content'] = 'Page not found';
		}

		$data['page'] = $page;
		$data['url']  = [
			'path'   => $request->getUri()->getPath(),
			'query'  => $request->getUri()->getQuery(),
			'params' => $args,
			'page'   => $args['page'] ?? '',
		];

		return $this->twigRenderer->template($response, 'admin/docs.twig', $data);
	}
}
