<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

final class AuthDeniedAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
	) {}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$response = $response->withStatus(403);
		return $this->twigRenderer->template($response, 'admin/denied.twig', $args);
	}
}
