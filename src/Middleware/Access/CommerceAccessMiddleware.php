<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Middleware\Commerce\CommerceEditionMiddleware;

/**
 * Commerce Access Middleware.
 *
 * The store's admin pages show buyers' names, addresses and what they paid,
 * so they are a grant of their own: a user's access group must say
 * `commerce`. A super admin passes in the base class.
 *
 * What moves money or undoes a sale (a refund, a cancellation, running
 * fulfillment again) is for super admins only: those routes add
 * {@see AdminOnlyMiddleware} as well.
 *
 * This is the second of two locks. {@see CommerceEditionMiddleware}
 * says whether the site has Commerce at all; this says whether this user may see it.
 */
readonly class CommerceAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'commerce';

	/** A yes or no for the whole store: there are no per-operation grants. */
	protected function checkPermission(Actor $actor, string $operation, ServerRequestInterface $request): bool
	{
		return $actor->authority()->canCommerce();
	}
}
