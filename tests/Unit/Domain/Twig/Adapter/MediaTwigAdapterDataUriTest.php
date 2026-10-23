<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * cms.media.imageDataUri() embeds an ImageWorks render in the page as a
 * data: URI — for an SVG used as an <img>, which cannot load anything from
 * outside itself. It must never break the page around it: anything it
 * cannot embed is an empty string.
 */
final class MediaTwigAdapterDataUriTest extends TestCase
{
	private ImageGenerator&MockObject $generator;

	/** @var array<string,mixed> */
	private array $member = ['id' => 'davide', 'image' => ['name' => 'face.jpg', 'size' => 1200, 'width' => 800, 'height' => 800]];

	protected function setUp(): void
	{
		$this->generator = $this->createMock(ImageGenerator::class);
	}

	private function adapter(): MediaTwigAdapter
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new MediaTwigAdapter($this->createMock(ObjectFetcher::class), $this->createMock(Config::class), $this->generator, $loggerFactory);
	}

	private function imageResponse(string $body, string $type = 'image/webp', int $status = 200): Response
	{
		$response = (new Response())->withStatus($status)->withHeader('Content-Type', $type);
		$response->getBody()->write($body);

		return $response;
	}

	public function testEmbedsTheRenderAsBase64WithItsType(): void
	{
		$this->generator->expects($this->once())->method('generateImage')
			->with('users', 'davide', 'image', ['w' => 68, 'h' => 68, 'fit' => 'crop', 'fm' => 'webp'])
			->willReturn($this->imageResponse('RIFFwebp'));

		$uri = $this->adapter()->imageDataUri($this->member, ['w' => 68, 'h' => 68, 'fit' => 'crop', 'fm' => 'webp'], ['collection' => 'users']);

		$this->assertSame('data:image/webp;base64,' . base64_encode('RIFFwebp'), $uri);
	}

	public function testNoImageIsAnEmptyStringAndNoRender(): void
	{
		$this->generator->expects($this->never())->method('generateImage');

		$this->assertSame('', $this->adapter()->imageDataUri(['id' => 'chaki', 'image' => ['size' => 0]], ['w' => 68], ['collection' => 'users']));
		$this->assertSame('', $this->adapter()->imageDataUri(['id' => 'chaki'], ['w' => 68], ['collection' => 'users']));
		$this->assertSame('', $this->adapter()->imageDataUri(null));
	}

	public function testARenderThatFailsIsAnEmptyString(): void
	{
		$this->generator->method('generateImage')->willThrowException(new \RuntimeException('Imagick exploded'));

		$this->assertSame('', $this->adapter()->imageDataUri($this->member, ['w' => 68], ['collection' => 'users']));
	}

	public function testARenderOverTheCapIsAnEmptyString(): void
	{
		$this->generator->method('generateImage')->willReturn($this->imageResponse(str_repeat('x', MediaTwigAdapter::DATA_URI_MAX_BYTES + 1)));

		$this->assertSame('', $this->adapter()->imageDataUri($this->member, [], ['collection' => 'users']));
	}

	public function testAResponseThatIsNotAnImageIsAnEmptyString(): void
	{
		$this->generator->method('generateImage')->willReturn($this->imageResponse('{"error":1}', 'application/json'));

		$this->assertSame('', $this->adapter()->imageDataUri($this->member, ['w' => 68], ['collection' => 'users']));
	}

	public function testANestedPropertyIsNotSupported(): void
	{
		$this->generator->expects($this->never())->method('generateImage');

		$this->assertSame('', $this->adapter()->imageDataUri(['id' => 'x', 'card' => ['image' => ['size' => 5]]], [], ['property' => 'card.image']));
	}
}
