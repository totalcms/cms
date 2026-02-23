<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminDataViewsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ObjectFetcher $objectFetcher,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $args['id'] ?? '';

		// Validate view exists (skip for index and new)
		if ($id !== '' && $id !== 'new' && !$this->objectFetcher->existsObject('dataviews', $id)) {
			return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
				'url' => ['path' => $request->getUri()->getPath(), 'page' => '404'],
			]);
		}

		return $this->twigRenderer->template($response, 'admin/dataviews/form.twig', [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'page'       => 'dataviews',
				'id'         => $id,
				'collection' => 'dataviews',
			],
		]);
	}
}
