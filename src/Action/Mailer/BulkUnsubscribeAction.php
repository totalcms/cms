<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Renderer\TwigRenderer;

/**
 * The unsubscribe link in Bulk Send mail.
 *
 * GET  /admin/unsubscribe/{token} shows a confirmation with a button. Mail
 *      scanners and link previews fetch links, so a GET changes nothing.
 * POST /admin/unsubscribe/{token} unsubscribes: the page's button, or a
 *      mail client's one-click button (RFC 8058, `List-Unsubscribe=One-Click`).
 *
 * The signed token is the authorization, so there is no CSRF field: a forged
 * request could only unsubscribe the person the link was sent to.
 */
readonly class BulkUnsubscribeAction
{
	public function __construct(
		private BulkUnsubscribe $unsubscribe,
		private TwigRenderer $twigRenderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$token = $args['token'] ?? '';
		$post  = $request->getMethod() === 'POST';
		$valid = $post ? $this->unsubscribe->unsubscribe($token) : $this->unsubscribe->isValid($token);

		$state = match (true) {
			!$valid                         => 'invalid',
			$token === BulkUnsubscribe::PREVIEW => 'preview',
			$post                           => 'done',
			default                         => 'confirm',
		};

		return $this->twigRenderer->template($response, 'admin/unsubscribe.twig', ['state' => $state, 'token' => $token])
			->withStatus($valid ? 200 : 400);
	}
}
