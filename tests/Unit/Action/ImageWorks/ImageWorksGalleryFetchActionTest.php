<?php

namespace Tests\Unit\Action\ImageWorks;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\ImageWorks\ImageWorksGalleryFetchAction;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksGalleryFetchActionTest extends TestCase
{
	private ImageWorksGalleryFetchAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $imageGenerator;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->imageGenerator = $this->createMock(ImageGenerator::class);
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
			->with('products', 'product-1', 'gallery', 'image1.jpg', $this->callback(fn ($params): bool => $params['w'] === '400'
					&& $params['h'] === '300'
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
			'sharpen'  => '20',
		];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(fn ($params): bool => $params['w'] === '800'
					&& $params['h'] === '600'
					&& $params['fit'] === 'crop'
					&& $params['sharpen'] === '20'
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
}
