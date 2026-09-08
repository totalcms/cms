<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessor;
use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Domain\Video\Provider\VideoProvider;
use TotalCMS\Domain\Video\Service\VideoMetadataFetcher;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

/**
 * PropertyDataProcessor's video branch (save pipeline, spec
 * "Save pipeline" in docs/planning/3.5.x/video-field.md).
 *
 * VideoUrlResolver and VideoMetadataFetcher are both `final`, so they can't
 * be doubled directly (PHPUnit refuses to mock final classes). Instead we
 * build REAL instances over doubled boundary interfaces: a fake VideoProvider
 * feeds the resolver a controlled VideoInfo, and a mocked HttpClientInterface
 * feeds the fetcher a controlled oEmbed response — call counts on those
 * interfaces stand in for "resolver called" / "fetcher called".
 */
final class PropertyDataProcessorVideoTest extends TestCase
{
	/** Build a VideoProvider double that matches everything and returns $info. */
	private function providerReturning(VideoInfo $info): VideoProvider
	{
		$provider = $this->createMock(VideoProvider::class);
		$provider->method('id')->willReturn($info->provider);
		$provider->method('matches')->willReturn(true);
		$provider->method('parse')->willReturn($info);
		$provider->method('embedQuery')->willReturn('');

		return $provider;
	}

	/** @return array{0:PropertyDataProcessor,1:MockObject&HttpClientInterface} */
	private function processorWith(VideoInfo $info): array
	{
		$resolver = new VideoUrlResolver([$this->providerReturning($info)]);
		$http     = $this->createMock(HttpClientInterface::class);
		$fetcher  = new VideoMetadataFetcher($http, $this->createStub(LoggerInterface::class));

		return [new PropertyDataProcessor($resolver, $fetcher), $http];
	}

	// ── 1. empty url ──────────────────────────────────────────────────────

	public function testEmptyUrlClearsDerivedKeysWithoutFetchingOrTouchingPoster(): void
	{
		$poster = ['name' => 'poster.jpg', 'mime' => 'image/jpeg'];
		$video  = new VideoData([
			'url'         => '   ',
			'provider'    => 'youtube',
			'videoId'     => 'abc123',
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Old title',
			'aspectRatio' => '4:3',
			'poster'      => $poster,
		]);

		[$processor, $http] = $this->processorWith(new VideoInfo('youtube', 'x', '', '', null));
		$http->expects($this->never())->method('request');

		$result = $processor->processBeforeSave($video);

		$this->assertSame('', $result->provider());
		$this->assertSame('', $result->videoId());
		$this->assertSame('', $result->thumbnail());
		$this->assertSame('', $result->title());
		$this->assertSame('16:9', $result->aspectRatio());
		$this->assertSame($poster, $result->poster());
	}

	// ── 2. url set, thumbnail empty → resolver + fetcher both run ─────────

	public function testEmptyThumbnailCallsResolverAndFetcherAndFillsDerivedKeys(): void
	{
		$video = new VideoData(['url' => 'https://vimeo.com/123456']);

		$info = new VideoInfo(
			provider: 'vimeo',
			videoId: '123456',
			embedUrl: 'https://player.vimeo.com/video/123456',
			thumbnail: '',
			oembedEndpoint: 'https://vimeo.com/api/oembed.json?url=x',
		);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->once())
			->method('request')
			->willReturn(new HttpResponse(200, json_encode([
				'thumbnail_url' => 'https://i.vimeocdn.com/video/123.jpg',
				'title'         => 'A Vimeo Video',
				'width'         => 1280,
				'height'        => 720,
			])));

		$result = $processor->processBeforeSave($video);

		$this->assertSame('vimeo', $result->provider());
		$this->assertSame('123456', $result->videoId());
		$this->assertSame('https://i.vimeocdn.com/video/123.jpg', $result->thumbnail());
		$this->assertSame('A Vimeo Video', $result->title());
		$this->assertSame('16:9', $result->aspectRatio());
	}

	// ── 3. url set, thumbnail already present → fetcher skipped ───────────

	public function testExistingThumbnailSkipsFetchButStillRecomputesProviderAndVideoId(): void
	{
		$video = new VideoData([
			'url'       => 'https://vimeo.com/999',
			'thumbnail' => 'https://existing.example/thumb.jpg',
		]);

		$info = new VideoInfo(
			provider: 'vimeo',
			videoId: '999',
			embedUrl: 'https://player.vimeo.com/video/999',
			thumbnail: '',
			oembedEndpoint: 'https://vimeo.com/api/oembed.json?url=x',
		);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->never())->method('request');

		$result = $processor->processBeforeSave($video);

		$this->assertSame('vimeo', $result->provider());
		$this->assertSame('999', $result->videoId());
	}

	// ── 3b. stored provider/videoId match the resolved ones, thumbnail
	//        still empty (a previously failed fetch) → fetcher NOT re-run ──

	public function testUnchangedUrlAfterAFailedFetchDoesNotRefetch(): void
	{
		// Same provider/videoId already stored (a previous save resolved this
		// exact URL) but thumbnail is still empty — that earlier fetch failed.
		// Re-saving the object (e.g. editing an unrelated field) must not hit
		// the network again.
		$video = new VideoData([
			'url'      => 'https://vimeo.com/999',
			'provider' => 'vimeo',
			'videoId'  => '999',
		]);

		$info = new VideoInfo(
			provider: 'vimeo',
			videoId: '999',
			embedUrl: 'https://player.vimeo.com/video/999',
			thumbnail: '',
			oembedEndpoint: 'https://vimeo.com/api/oembed.json?url=x',
		);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->never())->method('request');

		$result = $processor->processBeforeSave($video);

		$this->assertSame('vimeo', $result->provider());
		$this->assertSame('999', $result->videoId());
		$this->assertSame('', $result->thumbnail());
	}

	// ── 4. provider file/unknown → fetcher never called ────────────────────

	public function testFileProviderNeverCallsFetcher(): void
	{
		$video = new VideoData(['url' => 'https://example.com/clip.mp4']);
		$info  = new VideoInfo('file', '', 'https://example.com/clip.mp4', '', null);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->never())->method('request');

		$result = $processor->processBeforeSave($video);

		$this->assertSame('file', $result->provider());
	}

	public function testUnknownProviderNeverCallsFetcher(): void
	{
		$video = new VideoData(['url' => 'https://example.com/whatever']);
		$info  = new VideoInfo('unknown', '', 'https://example.com/whatever', '', null);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->never())->method('request');

		$result = $processor->processBeforeSave($video);

		$this->assertSame('unknown', $result->provider());
	}

	// ── 5. providers allow-list rejects a disallowed provider ─────────────

	public function testProviderNotInAllowListThrowsDomainException(): void
	{
		$video = new VideoData(
			['url' => 'https://youtu.be/abc123'],
			['providers' => ['vimeo', 'livid']],
		);

		$info = new VideoInfo('youtube', 'abc123', 'https://www.youtube-nocookie.com/embed/abc123', 'https://img.youtube.com/vi/abc123/hqdefault.jpg', null);

		[$processor, $http] = $this->processorWith($info);
		$http->expects($this->never())->method('request');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Video URL is a youtube link; this field accepts: vimeo, livid');

		$processor->processBeforeSave($video);
	}

	public function testProviderInAllowListIsAccepted(): void
	{
		$video = new VideoData(
			['url' => 'https://youtu.be/abc123'],
			['providers' => ['youtube', 'vimeo']],
		);

		$info = new VideoInfo('youtube', 'abc123', 'https://www.youtube-nocookie.com/embed/abc123', 'https://img.youtube.com/vi/abc123/hqdefault.jpg', null);

		[$processor] = $this->processorWith($info);

		$result = $processor->processBeforeSave($video);

		$this->assertSame('youtube', $result->provider());
	}

	// ── 6. poster present → hashed via the existing image processing step ──

	public function testPosterIsHashedThroughTheExistingImageProcessingStep(): void
	{
		$video = new VideoData([
			'url'    => 'https://example.com/clip.mp4',
			'poster' => ['name' => 'poster.jpg', 'mime' => 'image/jpeg', 'alt' => 'a poster', 'size' => 12345],
		]);

		$info = new VideoInfo('file', '', 'https://example.com/clip.mp4', '', null);

		[$processor] = $this->processorWith($info);

		$result = $processor->processBeforeSave($video);

		$poster = $result->poster();
		$this->assertArrayHasKey('hash', $poster);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $poster['hash']);
	}

	public function testNoPosterIsSafe(): void
	{
		$video = new VideoData(['url' => 'https://example.com/clip.mp4']);
		$info  = new VideoInfo('file', '', 'https://example.com/clip.mp4', '', null);

		[$processor] = $this->processorWith($info);

		$result = $processor->processBeforeSave($video);

		$this->assertSame([], $result->poster());
	}

	public function testEmptyShapedPosterIsNotHashed(): void
	{
		// The admin field always round-trips a fully-shaped image object through
		// the poster sub-field, even with nothing uploaded — VideoData::hasPoster()
		// requires a real name+size, so this must skip processImageData() entirely
		// (no hash key added) rather than hash an empty upload.
		$video = new VideoData([
			'url'    => 'https://example.com/clip.mp4',
			'poster' => ['name' => '', 'size' => 0, 'mime' => '', 'alt' => ''],
		]);
		$info = new VideoInfo('file', '', 'https://example.com/clip.mp4', '', null);

		[$processor] = $this->processorWith($info);

		$result = $processor->processBeforeSave($video);

		$this->assertArrayNotHasKey('hash', $result->poster());
	}
}
