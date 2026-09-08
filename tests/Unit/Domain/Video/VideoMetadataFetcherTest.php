<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Video;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Service\VideoMetadataFetcher;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

final class VideoMetadataFetcherTest extends TestCase
{
	/**
	 * Case 1: No oembedEndpoint — no request, return info's thumbnail, empty title, info's ratio.
	 */
	public function testFetchWithoutOembedEndpointMakesNoRequest(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->never())->method('request');

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'YouTube',
			videoId: 'dQw4w9WgXcQ',
			embedUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
			thumbnail: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/default.jpg',
			oembedEndpoint: null,
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/default.jpg',
			'title' => '',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * A thumbnail the provider derived from the URL beats the one oEmbed
	 * returns (Publitio's is a 300×200 centre crop); title and ratio still
	 * come from the response.
	 */
	public function testProviderDerivedThumbnailBeatsOembedThumbnail(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'type' => 'video',
			'title' => 'weaversspace-intro',
			'width' => 1920,
			'height' => 1080,
			'thumbnail_url' => 'https://media.weaversspace.com/file/w_300,h_200,c_fill/weaversspace/play/weaversspace-intro-z.jpg',
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())->method('request')->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'publitio',
			videoId: 'weaversspace/play/weaversspace-intro-z',
			embedUrl: 'https://media.weaversspace.com/file/weaversspace/play/weaversspace-intro-z.html',
			thumbnail: 'https://media.weaversspace.com/file/w_1280/weaversspace/play/weaversspace-intro-z.jpg',
			oembedEndpoint: 'https://media.publit.io/oembed?url=x&format=json',
		);

		$result = (new VideoMetadataFetcher($http, $logger))->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://media.weaversspace.com/file/w_1280/weaversspace/play/weaversspace-intro-z.jpg',
			'title' => 'weaversspace-intro',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 2: 200 with proper JSON → thumbnail from thumbnail_url (https only),
	 * title trimmed to 200 chars, aspectRatio computed (16:9).
	 */
	public function testFetchWith200JsonSuccess(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'https://i.example.com/x.jpg',
			'title' => '  My Video ',
			'width' => 1280,
			'height' => 720,
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->with(
				'GET',
				'https://vimeo.com/api/oembed.json',
				['timeout' => 3, 'headers' => ['Accept: application/json'], 'max_bytes' => 65536],
			)
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Vimeo',
			videoId: '123456',
			embedUrl: 'https://player.vimeo.com/video/123456',
			// No provider-derived thumbnail: the oEmbed one is taken (a derived one would win — see testProviderDerivedThumbnailBeatsOembedThumbnail).
			thumbnail: '',
			oembedEndpoint: 'https://vimeo.com/api/oembed.json',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://i.example.com/x.jpg',
			'title' => 'My Video',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 3: width: 1080, height: 1920 → 9:16 (reduced by gcd).
	 */
	public function testFetchComputesAspectRatioFromDimensions(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'https://example.com/thumb.jpg',
			'title' => 'Video',
			'width' => 1080,
			'height' => 1920,
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '789',
			embedUrl: 'https://example.com/embed',
			// No provider-derived thumbnail: the oEmbed one is taken (a derived one would win — see testProviderDerivedThumbnailBeatsOembedThumbnail).
			thumbnail: '',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://example.com/thumb.jpg',
			'title' => 'Video',
			'aspectRatio' => '9:16',
		], $result);
	}

	/**
	 * Case 4: Exception during request → empty thumbnail/title, default ratio, logger info called.
	 */
	public function testFetchHandlesException(): void
	{
		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willThrowException(new \RuntimeException('timeout'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('Video metadata fetch failed', $this->isType('array'));

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '123',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/thumb.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://example.com/thumb.jpg',
			'title' => '',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 5: 404 response → same as exception.
	 */
	public function testFetchHandles404(): void
	{
		$response = new HttpResponse(404, 'Not Found');

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('Video metadata fetch failed', $this->isType('array'));

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '456',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/thumb.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://example.com/thumb.jpg',
			'title' => '',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 6: Body is not JSON → same as exception.
	 */
	public function testFetchHandlesNonJsonResponse(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn(null);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('Video metadata fetch failed', $this->isType('array'));

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '789',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/thumb.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://example.com/thumb.jpg',
			'title' => '',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 7: thumbnail_url: "javascript:alert(1)" → thumbnail empty.
	 */
	public function testFetchRejectsNonHttpsThumbnail(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'javascript:alert(1)',
			'title' => 'Video',
			'width' => 1280,
			'height' => 720,
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '123',
			embedUrl: 'https://example.com/embed',
			// No provider-derived thumbnail: the oEmbed one is taken (a derived one would win — see testProviderDerivedThumbnailBeatsOembedThumbnail).
			thumbnail: '',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => '',
			'title' => 'Video',
			'aspectRatio' => '16:9',
		], $result);
	}

	/**
	 * Case 8: Multi-byte title (250 é characters) → truncated to 200 chars, valid UTF-8.
	 */
	public function testFetchTruncatesMultibyteTitle(): void
	{
		$longTitle = str_repeat('é', 250);
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'https://example.com/thumb.jpg',
			'title' => $longTitle,
			'width' => 1280,
			'height' => 720,
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: 'abc',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/thumb.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		// Title should be exactly 200 characters long and valid UTF-8
		$this->assertSame(200, mb_strlen($result['title']));
		$this->assertTrue(mb_check_encoding($result['title'], 'UTF-8'));
		$this->assertSame(str_repeat('é', 200), $result['title']);
	}

	/**
	 * Final review fix (Minor #7): width/height sent as numeric strings
	 * ("1080"/"1920", a real-world oEmbed quirk) must still compute a
	 * reduced aspect ratio instead of falling back to the default.
	 */
	public function testFetchComputesAspectRatioFromNumericStringDimensions(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'https://example.com/thumb.jpg',
			'title' => 'Video',
			'width' => '1080',
			'height' => '1920',
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '789',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/default.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame('9:16', $result['aspectRatio']);
	}

	/**
	 * A non-numeric or zero/negative width/height string must not blow up
	 * gcd() with a division by zero — it should just fall back to the
	 * default ratio like any other unusable value.
	 */
	public function testFetchIgnoresNonNumericOrZeroStringDimensions(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([
			'thumbnail_url' => 'https://example.com/thumb.jpg',
			'title' => 'Video',
			'width' => 'not-a-number',
			'height' => '0',
		]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: '789',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/default.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame('16:9', $result['aspectRatio']);
	}

	/**
	 * Case 9: JSON array (list) in response → treated as failure, logged.
	 */
	public function testFetchRejectsJsonArray(): void
	{
		$response = $this->createMock(HttpResponse::class);
		$response->expects($this->once())->method('isSuccess')->willReturn(true);
		$response->expects($this->once())->method('json')->willReturn([1, 2, 3]);

		$http = $this->createMock(HttpClientInterface::class);
		$http->expects($this->once())
			->method('request')
			->willReturn($response);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('Video metadata fetch failed', $this->isType('array'));

		$info = new VideoInfo(
			provider: 'Provider',
			videoId: 'def',
			embedUrl: 'https://example.com/embed',
			thumbnail: 'https://example.com/thumb.jpg',
			oembedEndpoint: 'https://example.com/oembed',
			aspectRatio: '16:9',
		);

		$fetcher = new VideoMetadataFetcher($http, $logger);
		$result = $fetcher->fetch($info);

		$this->assertSame([
			'thumbnail' => 'https://example.com/thumb.jpg',
			'title' => '',
			'aspectRatio' => '16:9',
		], $result);
	}
}
