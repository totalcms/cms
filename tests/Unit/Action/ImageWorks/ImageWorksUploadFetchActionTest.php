<?php

namespace Tests\Unit\Action\ImageWorks;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\ImageWorks\ImageWorksUploadFetchAction;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksUploadFetchActionTest extends TestCase
{
	private ImageWorksUploadFetchAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $imageGenerator;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->imageGenerator = $this->createMock(ImageGenerator::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new ImageWorksUploadFetchAction($this->imageGenerator);
	}

	public function testGeneratesUploadImageSuccessfully(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-1',
			'property'   => 'images',
			'name'       => 'photo.jpg',
		];

		$queryParams = ['w' => '800', 'h' => '600'];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateUploadImage')
			->with('blog', 'post-1', 'images', 'photo.jpg', $queryParams)
			->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}

	public function testPassesAllArgsToGenerator(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-5',
			'property'   => 'gallery',
			'name'       => 'image-2.png',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateUploadImage')
			->with('products', 'product-5', 'gallery', 'image-2.png', [])
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesQueryParametersToGenerator(): void
	{
		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
			'name'       => 'file.jpg',
		];

		$queryParams = [
			'w'   => '200',
			'fit' => 'contain',
		];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateUploadImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(fn ($params): bool => $params['w'] === '200' && $params['fit'] === 'contain'))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testThrowsNotFoundExceptionOnError(): void
	{
		$args = [
			'collection' => 'blog',
			'id'         => 'post-1',
			'property'   => 'images',
			'name'       => 'missing.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$this->imageGenerator->method('generateUploadImage')
			->willThrowException(new \Exception('Upload not found'));

		$this->expectException(HttpNotFoundException::class);
		$this->expectExceptionMessage('Image not found');

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsImageResponse(): void
	{
		$args = [
			'collection' => 'gallery',
			'id'         => 'album-1',
			'property'   => 'photos',
			'name'       => 'sunset.jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->method('generateUploadImage')->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}
}
