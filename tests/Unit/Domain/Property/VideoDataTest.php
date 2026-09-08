<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\VideoData;
use TotalCMS\Domain\Video\Data\VideoInfo;

/**
 * VideoData is a first-class value object (like ImageData/FileData/ColorData),
 * not a card: an author-supplied URL, provider-derived metadata cached at save
 * time, and an optional uploaded poster image, over a key set this class owns.
 * Behavior beyond storage/retrieval (fetching metadata, allow-listing
 * providers) belongs to the save pipeline (PropertyDataProcessor), not here.
 */
#[CoversClass(VideoData::class)]
final class VideoDataTest extends TestCase
{
	public function testIsAPlainPropertyDataNotACard(): void
	{
		$video = new VideoData(['url' => 'https://youtu.be/abc123']);
		$this->assertInstanceOf(\TotalCMS\Domain\Property\Data\PropertyData::class, $video);
		$this->assertNotInstanceOf(\TotalCMS\Domain\Property\Data\CardData::class, $video);
	}

	public function testTypedGettersReadTheStoredValues(): void
	{
		$video = new VideoData([
			'url'         => 'https://youtu.be/abc123',
			'provider'    => 'youtube',
			'videoId'     => 'abc123',
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Getting started with Total CMS',
			'aspectRatio' => '16:9',
		]);

		$this->assertSame('https://youtu.be/abc123', $video->url());
		$this->assertSame('youtube', $video->provider());
		$this->assertSame('abc123', $video->videoId());
		$this->assertSame('https://img.youtube.com/vi/abc123/hqdefault.jpg', $video->thumbnail());
		$this->assertSame('Getting started with Total CMS', $video->title());
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testGettersReturnEmptyStringsWhenKeysAreAbsent(): void
	{
		$video = new VideoData([]);

		$this->assertSame('', $video->url());
		$this->assertSame('', $video->provider());
		$this->assertSame('', $video->videoId());
		$this->assertSame('', $video->thumbnail());
		$this->assertSame('', $video->title());
		// aspectRatio is the one key with a real default — the responsive
		// wrapper always needs a ratio to lay out with.
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testConstructsFromNull(): void
	{
		$video = new VideoData();

		$this->assertSame('', $video->url());
		$this->assertSame([], $video->poster());
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testConstructsFromAJsonString(): void
	{
		// The admin form and the CSV single-column fallback both hand complex
		// field values over as JSON strings.
		$video = new VideoData('{"url":"https://youtu.be/abc123","provider":"youtube","title":"Hi"}');

		$this->assertSame('https://youtu.be/abc123', $video->url());
		$this->assertSame('youtube', $video->provider());
		$this->assertSame('Hi', $video->title());
	}

	public function testNonJsonStringAndListsDecodeToAnEmptyVideo(): void
	{
		$this->assertSame('', (new VideoData('Array'))->url());
		$this->assertSame('', (new VideoData(['a', 'b']))->url());
	}

	public function testTransformReturnsTheSixKeysAndOmitsAnAbsentPoster(): void
	{
		$video = new VideoData([
			'url'         => 'https://youtu.be/abc123',
			'provider'    => 'youtube',
			'videoId'     => 'abc123',
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Getting started with Total CMS',
			'aspectRatio' => '16:9',
		]);

		$this->assertSame([
			'url'         => 'https://youtu.be/abc123',
			'provider'    => 'youtube',
			'videoId'     => 'abc123',
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Getting started with Total CMS',
			'aspectRatio' => '16:9',
		], $video->transform());
	}

	public function testTransformIncludesAStoredPoster(): void
	{
		$poster = ['name' => 'poster.jpg', 'size' => 12345];
		$video  = new VideoData(['url' => 'https://youtu.be/abc123', 'poster' => $poster]);

		$this->assertSame($poster, $video->transform()['poster']);
	}

	/**
	 * The admin form round-trips a fully-shaped empty image object for a poster
	 * that was never uploaded — `width`/`height` arrive as null. Storing that
	 * fails schema validation ("/video/poster/width must match the type:
	 * integer"), so transform() writes `poster` only for a real upload.
	 */
	public function testTransformOmitsAnEmptyShapedPosterFromTheAdminForm(): void
	{
		$video = new VideoData([
			'url'    => 'https://vimeo.com/1',
			'poster' => ['name' => '', 'size' => 0, 'width' => null, 'height' => null, 'alt' => '', 'mime' => ''],
		]);

		$this->assertArrayNotHasKey('poster', $video->transform());
	}

	public function testTransformDropsTheLegacyCardId(): void
	{
		// Records written while the field was still a card carry an `id` key
		// forced to the property name. It is not part of the shape any more —
		// reading one must not resurrect it on the next save.
		$video = new VideoData([
			'id'  => 'promo',
			'url' => 'https://youtu.be/abc123',
		]);

		$this->assertArrayNotHasKey('id', $video->transform());
		$this->assertSame('https://youtu.be/abc123', $video->url());
	}

	public function testPosterReturnsEmptyArrayWhenNone(): void
	{
		$video = new VideoData(['url' => 'https://youtu.be/abc123']);

		$this->assertSame([], $video->poster());
		$this->assertFalse($video->hasPoster());
	}

	public function testPosterReturnsTheStoredImageArray(): void
	{
		$poster = ['name' => 'poster.jpg', 'mime' => 'image/jpeg', 'alt' => '', 'size' => 12345];
		$video  = new VideoData(['url' => 'https://youtu.be/abc123', 'poster' => $poster]);

		$this->assertSame($poster, $video->poster());
		$this->assertTrue($video->hasPoster());
	}

	public function testPosterWithAnEmptyShapeIsNotAPoster(): void
	{
		// The admin field always round-trips a fully-shaped image object
		// through its poster sub-field, including on a video with no
		// uploaded poster, where every key is present but empty/zeroed.
		// hasPoster() must not mistake that shape for a real upload.
		$poster = ['name' => '', 'size' => 0, 'mime' => '', 'alt' => ''];
		$video  = new VideoData(['url' => 'https://youtu.be/abc123', 'poster' => $poster]);

		$this->assertSame($poster, $video->poster());
		$this->assertFalse($video->hasPoster());
	}

	public function testPosterWithANameButNoSizeIsNotAPoster(): void
	{
		// Belt-and-braces: a stray name with a zero/missing size (e.g. a
		// partially-cleared record) still shouldn't count as an upload.
		$video = new VideoData(['url' => 'https://youtu.be/abc123', 'poster' => ['name' => 'poster.jpg', 'size' => 0]]);

		$this->assertFalse($video->hasPoster());
	}

	public function testWithDerivedSetsProviderAndVideoIdFromInfoAndMetaFromFetch(): void
	{
		$video = new VideoData(['url' => 'https://youtu.be/abc123']);
		$info  = new VideoInfo(
			provider: 'youtube',
			videoId: 'abc123',
			embedUrl: 'https://www.youtube-nocookie.com/embed/abc123',
			thumbnail: 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			oembedEndpoint: null,
		);

		$video->withDerived($info, [
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Getting started with Total CMS',
			'aspectRatio' => '16:9',
		]);

		$this->assertSame('youtube', $video->provider());
		$this->assertSame('abc123', $video->videoId());
		$this->assertSame('https://img.youtube.com/vi/abc123/hqdefault.jpg', $video->thumbnail());
		$this->assertSame('Getting started with Total CMS', $video->title());
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testWithDerivedFallsBackToInfoWhenMetaIsMissingKeys(): void
	{
		$video = new VideoData(['url' => 'https://example.com/video.mp4']);
		$info  = new VideoInfo(
			provider: 'file',
			videoId: '',
			embedUrl: '',
			thumbnail: '',
			oembedEndpoint: null,
		);

		$video->withDerived($info, []);

		$this->assertSame('file', $video->provider());
		$this->assertSame('', $video->videoId());
		$this->assertSame('', $video->thumbnail());
		$this->assertSame('', $video->title());
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testWithDerivedKeepsStoredThumbnailAndTitleWhenMetaIsEmpty(): void
	{
		// A skipped fetch (PropertyDataProcessor rule 3) passes an empty
		// $meta — provider/videoId still get recomputed, but a previously
		// resolved thumbnail/title must survive, not get blanked.
		$video = new VideoData([
			'url'         => 'https://vimeo.com/999',
			'provider'    => 'vimeo',
			'videoId'     => 'old-id',
			'thumbnail'   => 'https://existing.example/thumb.jpg',
			'title'       => 'Existing Title',
			'aspectRatio' => '4:3',
		]);
		$info = new VideoInfo(
			provider: 'vimeo',
			videoId: '999',
			embedUrl: 'https://player.vimeo.com/video/999',
			thumbnail: '',
			oembedEndpoint: 'https://vimeo.com/api/oembed.json?url=x',
		);

		$video->withDerived($info, []);

		$this->assertSame('vimeo', $video->provider());
		$this->assertSame('999', $video->videoId());
		$this->assertSame('https://existing.example/thumb.jpg', $video->thumbnail());
		$this->assertSame('Existing Title', $video->title());
		$this->assertSame('4:3', $video->aspectRatio());
	}

	public function testClearDerivedResetsDerivedKeysAndKeepsUrlAndPoster(): void
	{
		$poster = ['name' => 'poster.jpg', 'mime' => 'image/jpeg', 'alt' => ''];
		$video  = new VideoData([
			'url'         => 'https://youtu.be/abc123',
			'provider'    => 'youtube',
			'videoId'     => 'abc123',
			'thumbnail'   => 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
			'title'       => 'Getting started with Total CMS',
			'aspectRatio' => '4:3',
			'poster'      => $poster,
		]);

		$video->clearDerived();

		$this->assertSame('https://youtu.be/abc123', $video->url());
		$this->assertSame($poster, $video->poster());
		$this->assertSame('', $video->provider());
		$this->assertSame('', $video->videoId());
		$this->assertSame('', $video->thumbnail());
		$this->assertSame('', $video->title());
		$this->assertSame('16:9', $video->aspectRatio());
	}

	public function testToStringIsTitleAndUrlTrimmed(): void
	{
		$video = new VideoData(['url' => 'https://youtu.be/abc123', 'title' => 'Getting Started']);
		$this->assertSame('Getting Started https://youtu.be/abc123', (string)$video);
	}

	public function testToStringTrimsWhenTitleIsEmpty(): void
	{
		$video = new VideoData(['url' => 'https://youtu.be/abc123']);
		$this->assertSame('https://youtu.be/abc123', (string)$video);
	}

	public function testToStringTrimsWhenUrlIsEmpty(): void
	{
		$video = new VideoData(['title' => 'Getting Started']);
		$this->assertSame('Getting Started', (string)$video);
	}
}
