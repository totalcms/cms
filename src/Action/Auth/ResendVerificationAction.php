<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Display the "resend verification email" form.
 *
 * Public — used by anyone whose verification link expired or got lost. The
 * submission action ({@see ResendVerificationSubmitAction}) returns a generic
 * success regardless of whether the email exists, so this page does not leak
 * account state.
 */
readonly class ResendVerificationAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'] ?? $this->config->auth['collection'] ?? 'auth';

		return $this->twigRenderer->template($response, 'admin/resend-verification.twig', [
			'collection' => $collection,
		]);
	}
}
