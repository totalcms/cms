<?php

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Renderer\JsonRenderer;
use Odan\Session\SessionInterface;

/**
 * Action.
 */
final class AuthLoginAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private LoginService $loginService,
	) {}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$data = (array)$request->getParsedBody();

		if (!isset($data['email']) || !isset($data['password'])) {
			throw new HttpUnauthorizedException($request, 'Email and password are required');
		}

		$email      = $data['email'];
		$password   = $data['password'];
		$collection = $args['collection'] ?? '';

		try {
			$this->loginService->authenticate($email, $password, $collection);
		} catch (\Exception $e) {
			throw new HttpUnauthorizedException($request, $e->getMessage());
		}

		return $this->renderer->json($response, ['authenticated' => true]);
	}
}
