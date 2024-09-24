<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

final class AuthLoginAction
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
		return $this->twigRenderer->template($response, 'admin/login.twig', $args);
	}
}
