<?php

namespace Tests\Unit\Action\ImageWorks;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\ImageWorks\ImageWorksImageFetchAction;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksImageFetchActionTest extends TestCase
{
	private ImageWorksImageFetchAction $action;
	private ImageGenerator $imageGenerator;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->imageGenerator = $this->createMock(ImageGenerator::class);
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$this->action = new ImageWorksImageFetchAction($this->imageGenerator);
	}

	public function testGeneratesImageSuccessfully(): void
	{
		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'image',
			'format'     => 'jpg',
		];

		$this->request->method('getQueryParams')->willReturn(['w' => '300', 'h' => '200']);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateImage')
			->with('products', 'product-1', 'image', $this->callback(function ($params) {
				return $params['w'] === '300'
					&& $params['h'] === '200'
					&& $params['fm'] === 'jpg';
			}))
			->willReturn($imageResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($imageResponse, $result);
	}

	public function testUsesDefaultCollectionAndProperty(): void
	{
		$args = [
			'id'     => 'img-1',
			'format' => 'png',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateImage')
			->with('image', 'img-1', 'image', $this->anything())
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testAddsFormatToQueryParams(): void
	{
		$args = [
			'id'     => 'test',
			'format' => 'webp',
		];

		$this->request->method('getQueryParams')->willReturn(['w' => '100']);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->callback(function ($params) {
				return isset($params['fm']) && $params['fm'] === 'webp';
			}))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}

	public function testThrowsNotFoundExceptionOnError(): void
	{
		$args = [
			'id'     => 'nonexistent',
			'format' => 'jpg',
		];

		$this->request->method('getQueryParams')->willReturn([]);

		$this->imageGenerator->method('generateImage')
			->willThrowException(new \Exception('File not found'));

		$this->expectException(HttpNotFoundException::class);
		$this->expectExceptionMessage('Image not found');

		($this->action)($this->request, $this->response, $args);
	}

	public function testPassesQueryParametersToGenerator(): void
	{
		$args = [
			'id'     => 'test',
			'format' => 'jpg',
		];

		$queryParams = [
			'w'    => '500',
			'h'    => '400',
			'fit'  => 'crop',
			'blur' => '10',
		];

		$this->request->method('getQueryParams')->willReturn($queryParams);

		$imageResponse = $this->createMock(ResponseInterface::class);

		$this->imageGenerator->expects($this->once())
			->method('generateImage')
			->with($this->anything(), $this->anything(), $this->anything(), $this->callback(function ($params) use ($queryParams) {
				return $params['w'] === '500'
					&& $params['h'] === '400'
					&& $params['fit'] === 'crop'
					&& $params['blur'] === '10'
					&& $params['fm'] === 'jpg';
			}))
			->willReturn($imageResponse);

		($this->action)($this->request, $this->response, $args);
	}
}
