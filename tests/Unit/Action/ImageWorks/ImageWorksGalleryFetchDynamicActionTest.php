<?php

namespace Tests\Unit\Action\ImageWorks;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\ImageWorks\ImageWorksGalleryFetchDynamicAction;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksGalleryFetchDynamicActionTest extends TestCase
{
	private ImageWorksGalleryFetchDynamicAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $imageGenerator;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->imageGenerator = $this->createMock(ImageGenerator::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new ImageWorksGalleryFetchDynamicAction($this->imageGenerator);
	}

	public function testGeneratesDynamicGalleryImage(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'photos',
			'action'     => 'image-1.jpg',
		];

		$queryParams = ['w' => '500', 'h' => '400'];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('products', 'product-1', 'photos', 'image-1.jpg', $queryParams)
			->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}

	public function testUsesDefaultCollectionAndProperty(): void
	{
		$args = [
			'id'     => 'gallery-1',
			'action' => 'photo.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('gallery', 'gallery-1', 'gallery', 'photo.jpg', [])
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesActionAsImageName(): void
	{
		$args = [
			'id'     => 'album-1',
			'action' => 'sunset-beach.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), 'sunset-beach.jpg', $this->anything())
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesQueryParametersToGenerator(): void
	{
		$args = [
			'id'     => 'test',
			'action' => 'image.jpg',
		];

		$queryParams = [
			'w'    => '300',
			'h'    => '200',
			'fit'  => 'crop',
			'blur' => '5',
		];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(fn ($params): bool => $params === $queryParams))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testThrowsNotFoundExceptionOnError(): void
	{
		$args = [
			'id'     => 'gallery-1',
			'action' => 'missing.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$this->imageGenerator->method('generateGalleryImage')
			->willThrowException(new \Exception('Image file does not exist'));

		$this->expectException(HttpNotFoundException::class);
		$this->expectExceptionMessage('Image not found');

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsImageResponse(): void
	{
		$args = [
			'id'     => 'album-5',
			'action' => 'photo.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->method('generateGalleryImage')->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}

	public function testHandlesComplexActionNames(): void
	{
		$args = [
			'collection' => 'portfolio',
			'id'         => 'project-1',
			'property'   => 'images',
			'action'     => 'before-after-comparison.png',
		];

		$this->request->method('getQueryParams')->willReturn(['w' => '1200']);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('portfolio', 'project-1', 'images', 'before-after-comparison.png', $this->anything())
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}
}
