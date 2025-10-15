<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminMailerAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// Handle GET requests for the mailer page
		return $this->twigRenderer->template($response, 'admin/mailer.twig', [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'page'       => 'mailer',
				'id'         => $args['id'] ?? '',
				'collection' => 'mailer',
			],
		]);
	}
}
