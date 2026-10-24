<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminFileLinksAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private MediaToolAccess $access,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$this->access->assertCanRead($request, 'file');

		return $this->twigRenderer->template($response, 'admin/filelinks.twig');
	}
}
