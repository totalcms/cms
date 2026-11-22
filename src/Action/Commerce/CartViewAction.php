<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Answers with the cart: JSON, or a cart partial for an HTMX page.
 */
final readonly class CartViewAction
{
	public function __construct(private CartResponder $responder)
	{
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		return $this->responder->show($request, $response);
	}
}
