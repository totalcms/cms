<?php

declare(strict_types=1);

namespace Tests\Fakes;

use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * The HttpClientInterface every test app boots with: no request ever leaves
 * the process. Each call fails the way a dead network does — status 0, empty
 * body — so callers take their offline branch (VideoMetadataFetcher falls
 * back to the provider's static thumbnail, the license check stays cached,
 * and so on).
 *
 * Before this, bootstrap() bound the real Guzzle client and every object save
 * with a Vimeo/Loom/Bunny video URL made a live oEmbed request — 80+ per run,
 * ~100ms each, and a hard dependency on those services being up.
 *
 * A test that needs a canned response overrides the binding on its own
 * container (see createMockHttpClient() in tests/Pest.php).
 */
final class OfflineHttpClient implements HttpClientInterface
{
	/** @var list<array{method: string, url: string}> Every request a test tried to make. */
	public static array $attempted = [];

	public function request(string $method, string $url, array $options = []): HttpResponse
	{
		self::$attempted[] = ['method' => $method, 'url' => $url];

		return new HttpResponse(0, '');
	}
}
