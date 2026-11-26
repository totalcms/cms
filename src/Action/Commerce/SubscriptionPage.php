<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Middleware\Commerce\SubscriptionOwnerMiddleware;
use TotalCMS\Support\Config;

/**
 * What the three subscription routes share: the subscription the owner
 * check let through, and the address of its page for whoever is asking.
 */
final readonly class SubscriptionPage
{
	public function __construct(private Config $config)
	{
	}

	/** @throws HttpNotFoundException when the owner check did not run: nothing is shown without it */
	public function subscription(ServerRequestInterface $request): SubscriptionData
	{
		$subscription = $request->getAttribute(SubscriptionOwnerMiddleware::ATTRIBUTE);

		return $subscription instanceof SubscriptionData ? $subscription : throw new HttpNotFoundException($request);
	}

	/** The signed link the request carries, or '' for a signed-in customer who needs none. */
	public function token(ServerRequestInterface $request): string
	{
		$token = $request->getQueryParams()['t'] ?? '';

		return is_string($token) ? $token : '';
	}

	/**
	 * The page's own address, carrying on the link the visitor came by.
	 *
	 * @param array<string,string> $query
	 */
	public function url(ServerRequestInterface $request, SubscriptionData $subscription, string $part = '', array $query = [], bool $absolute = false): string
	{
		$query += array_filter(['t' => $this->token($request)]);
		$path = rtrim($this->config->api, '/') . "/commerce/subscription/{$subscription->id()}{$part}";

		return ($absolute ? rtrim($this->config->url, '/') : '') . $path . ($query === [] ? '' : '?' . http_build_query($query));
	}
}
