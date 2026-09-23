<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Docs\Service\DocsMarkdownRenderer;
use TotalCMS\Domain\Docs\Service\DocsPageLoader;
use TotalCMS\Domain\Docs\Service\DocsResource;
use TotalCMS\Renderer\TwigRenderer;

/**
 * The in-admin documentation viewer: serves the search index and co-located
 * images straight through, renders markdown pages into the docs template.
 */
readonly class AdminDocsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private DocsPageLoader $loader,
		private DocsMarkdownRenderer $markdown,
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
		$page     = $this->loader->sanitize($args['page'] ?? 'index');
		$resource = $this->loader->resolve($page);

		if ($resource->is(DocsResource::JSON)) {
			$response->getBody()->write((string)(file_get_contents($resource->path) ?: '{}'));

			return $response->withHeader('Content-Type', $resource->mime);
		}

		if ($resource->is(DocsResource::IMAGE)) {
			$response->getBody()->write((string)file_get_contents($resource->path));

			return $response
				->withHeader('Content-Type', $resource->mime)
				->withHeader('Cache-Control', 'public, max-age=3600');
		}

		if ($resource->is(DocsResource::MISSING)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		$contents = file_get_contents($resource->path);
		if ($resource->is(DocsResource::MARKDOWN)) {
			if (!$contents) {
				throw new \UnexpectedValueException("Unable to read Doc Page $page");
			}
			$rendered        = $this->markdown->render($contents);
			$data            = $rendered['data'];
			$data['content'] = $rendered['content'];
			$data['toc']     = $rendered['toc'];
		} else {
			$data = ['content' => $contents !== false ? $contents : 'Unable to read page'];
		}

		$data['page'] = $page;
		$data['url']  = [
			'path'   => $request->getUri()->getPath(),
			'query'  => $request->getUri()->getQuery(),
			'params' => $args,
			'page'   => 'docs',
		];
		$data['menu'] = $this->loader->menu();

		return $this->twigRenderer->template($response, 'admin/docs.twig', $data);
	}
}
