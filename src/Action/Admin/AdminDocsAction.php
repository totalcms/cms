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
		private TwigRenderer $twigRenderer
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$page = $args['page'] ?? 'index';

		$markdownFile = __DIR__ . "/../../../resources/docs/{$page}.md";

		if (!file_exists($markdownFile)) {
			throw new \UnexpectedValueException("Doc Page not found $page");
		}

		$contents = file_get_contents($markdownFile);
		if (!$contents) {
			throw new \UnexpectedValueException("Unable to read Doc Page $page");
		}

		$parsedown   = new \ParsedownExtra();
		$frontMatter = FrontMatterChain::create();
		$document    = $frontMatter->parse($contents);

		$data            = $document->getData();
		$data['content'] = $parsedown->text($document->getContent());
		$data['page']    = $page;

		return $this->twigRenderer->template($response, 'admin/docs.twig', $data);
	}
}
