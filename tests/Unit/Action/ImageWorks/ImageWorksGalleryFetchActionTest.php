<?php

namespace Tests\Unit\Action\ImageWorks;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use TotalCMS\Action\ImageWorks\ImageWorksGalleryFetchAction;
use TotalCMS\Domain\ImageWorks\Exception\RenderBusyException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksGalleryFetchActionTest extends TestCase
{
	private ImageWorksGalleryFetchAction $action;
	private MockObject $imageGenerator;
	private MockObject $request;
	private MockObject $response;

	protected function setUp(): void
	{
		$this->imageGenerator = $this->createMock(ImageGenerator::class);
		// The public routes render through forPublicRoute(); the clone it
		// hands back is the generator itself here.
		$this->imageGenerator->method('forPublicRoute')->willReturnSelf();
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new ImageWorksGalleryFetchAction($this->imageGenerator);
	}

	public function testGeneratesGalleryImageSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
			'name'       => 'image1.jpg',
			'format'     => 'jpg',
		];

		$queryParams = ['w' => '400', 'h' => '300'];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('products', 'product-1', 'gallery', 'image1.jpg', $this->callback(fn ($params): bool => $params['w'] === 400
					&& $params['h'] === 300
					&& $params['fm'] === 'jpg'))
			->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}

	public function testUsesDefaultCollectionAndProperty(): void
	{
		$args = [
			'id'     => 'gallery-1',
			'name'   => 'photo.jpg',
			'format' => 'png',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('gallery', 'gallery-1', 'gallery', 'photo.jpg', $this->anything())
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testAddsFormatToQueryParams(): void
	{
		$args = [
			'id'     => 'test',
			'name'   => 'image.jpg',
			'format' => 'webp',
		];

		$this->request->method('getQueryParams')->willReturn(['w' => '100']);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(fn ($params): bool => isset($params['fm']) && $params['fm'] === 'webp'))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testThrowsNotFoundExceptionOnError(): void
	{
		$args = [
			'id'     => 'gallery-1',
			'name'   => 'missing.jpg',
			'format' => 'jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$this->imageGenerator->method('generateGalleryImage')
			->willThrowException(new \Exception('Gallery image not found'));

		$this->expectException(HttpNotFoundException::class);
		$this->expectExceptionMessage('Image not found');

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesQueryParametersToGenerator(): void
	{
		$args = [
			'id'     => 'test',
			'name'   => 'photo.jpg',
			'format' => 'jpg',
		];

		$queryParams = [
			'w'        => '800',
			'h'        => '600',
			'fit'      => 'crop',
			'sharp'    => '20',
		];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(fn ($params): bool => $params['w'] === 800
					&& $params['h'] === 600
					&& $params['fit'] === 'crop'
					&& $params['sharp'] === 20
					&& $params['fm'] === 'jpg'))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesNameArgToGenerator(): void
	{
		$args = [
			'id'     => 'album-5',
			'name'   => 'sunset-beach.jpg',
			'format' => 'jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), 'sunset-beach.jpg', $this->anything())
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	/**
	 * The request from the Nov 2026 sqlmap scan: it used to be a full-size
	 * render per variation. Now it is a 400 before the generator is touched.
	 */
	public function testRejectsAnInjectionPayloadBeforeAnyImageWork(): void
	{
		$args = [
			'collection' => 'showcase',
			'id'         => 'liceo-villa-fontana',
			'property'   => 'screenshots',
			'path'       => 'becas',
			'format'     => 'webp',
		];

		$this->request->method('getQueryParams')->willReturn([
			'w'     => '-5905 OR 7283=CAST((CHR(113)||CHR(122)) AS NUMERIC)-- KGNj',
			'q'     => '82',
			'cache' => '255cdb73',
		]);

		$this->imageGenerator->expects($this->never())->method('generateGalleryImage');

		$this->expectException(HttpBadRequestException::class);
		$this->expectExceptionMessage('Invalid ImageWorks parameter: w');

		($this->action)($this->request, $this->response, $args);
	}

	public function testAnswersBusyWith503AndRetryAfter(): void
	{
		$args = ['id' => 'album-1', 'name' => 'photo', 'format' => 'jpg'];

		$this->request->method('getQueryParams')->willReturn(['w' => '400']);
		$this->imageGenerator->method('generateGalleryImage')->willThrowException(new RenderBusyException(5));

		$result = ($this->action)($this->request, new Response(), $args);

		$this->assertSame(503, $result->getStatusCode());
		$this->assertSame('5', $result->getHeaderLine('Retry-After'));
		$this->assertSame('no-store', $result->getHeaderLine('Cache-Control'));
	}
}
