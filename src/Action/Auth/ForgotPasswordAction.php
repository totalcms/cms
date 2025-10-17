<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Display the forgot password form.
 */
readonly class ForgotPasswordAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private Config $config,
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
		// Get collection from URL or use default
		$collection = $args['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		return $this->twigRenderer->template($response, 'admin/forgot-password.twig', [
			'collection' => $collection,
		]);
	}
}
