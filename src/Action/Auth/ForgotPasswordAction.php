<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\SameOriginRedirect;

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

		$query = $request->getQueryParams();

		return $this->twigRenderer->template($response, 'admin/forgot-password.twig', [
			'collection' => $collection,
			// Where the visitor started, carried on to the reset email. Same
			// origin only; the reset form checks it again.
			'redirect'   => SameOriginRedirect::resolve(
				is_string($query['redirect'] ?? null) ? $query['redirect'] : '',
				$request->getUri()->getHost(),
				'',
			),
		]);
	}
}
