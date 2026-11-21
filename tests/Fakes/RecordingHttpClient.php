<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * An HttpClientInterface that answers from a queue of canned responses and
 * keeps every request it was asked to make, so a test can assert on the
 * method, URL and headers. With the queue empty it fails the way a dead
 * network does (status 0), like OfflineHttpClient.
 */
final class RecordingHttpClient implements HttpClientInterface
{
	/** @var list<array{method: string, url: string, options: array<string,mixed>}> */
	public array $requests = [];

	/** @var list<HttpResponse> */
	private array $queue;

	public function __construct(HttpResponse ...$queue)
	{
		$this->queue = array_values($queue);
	}

	public function push(HttpResponse $response): void
	{
		$this->queue[] = $response;
	}

	public function request(string $method, string $url, array $options = []): HttpResponse
	{
		$this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

		return array_shift($this->queue) ?? new HttpResponse(0, '');
	}

	/** @return list<string> the methods requested so far, in order */
	public function methods(): array
	{
		return array_column($this->requests, 'method');
	}
}
