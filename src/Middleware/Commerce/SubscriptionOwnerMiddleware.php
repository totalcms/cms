<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Commerce\Subscription\CustomerSubscriptions;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;

/**
 * Lets through only whoever a subscription belongs to: the signed-in
 * customer it was sold to, or someone holding its signed link (`?t=`).
 *
 * Anyone else gets a 404, the same as for a subscription that does not
 * exist, so these routes cannot be used to find subscription ids. The
 * subscription is put on the request as `subscription`.
 */
final readonly class SubscriptionOwnerMiddleware implements MiddlewareInterface
{
	public const ATTRIBUTE = 'subscription';

	public function __construct(private CustomerSubscriptions $subscriptions)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$id    = RouteContext::fromRequest($request)->getRoute()?->getArgument('id') ?? '';
		$token = $request->getQueryParams()['t'] ?? '';

		$subscription = $this->subscriptions->opened($id, is_string($token) ? $token : '');
		if (!$subscription instanceof SubscriptionData) {
			throw new HttpNotFoundException($request);
		}

		return $handler->handle($request->withAttribute(self::ATTRIBUTE, $subscription));
	}
}
