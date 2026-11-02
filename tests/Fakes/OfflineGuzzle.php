<?php

declare(strict_types=1);

namespace Tests\Fakes;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A Guzzle client for code that takes one directly rather than
 * HttpClientInterface: every request fails as if the network were down, the
 * same as OfflineHttpClient.
 */
final class OfflineGuzzle
{
	public static function client(): Client
	{
		return new Client(['handler' => static fn (RequestInterface $request): PromiseInterface => Create::rejectionFor(
			new ConnectException('Tests do not reach the network: ' . $request->getUri(), $request),
		)]);
	}
}
