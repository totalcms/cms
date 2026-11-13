<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\AdminImageworksAction;
use Psr\Log\NullLogger;
use ReflectionClass;
use TotalCMS\Domain\Extension\Service\GuardedImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

final class AdminImageworksActionTest extends TestCase
{
	private AdminImageworksAction $action;
	private MockObject $renderer;
	private MockObject $request;
	private MockObject $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminImageworksAction($this->renderer, mediaToolAccessAuthOff(), $this->filterRegistry());
	}

	private function filterRegistry(): ImageFilterRegistry
	{
		$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->imageworks = ['presets' => [], 'defaults' => [], 'filters' => []];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new ImageFilterRegistry($config, $loggerFactory);
	}

	public function testRendersImageworksTemplate(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/imageworks.twig',
				$this->callback(static function (array $data): bool {
					$names = array_column($data['filters'] ?? [], 'name');

					return in_array('ember', $names, true) && in_array('greyscale', $names, true);
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAThrowingExtensionLabelDoesNotBreakThePage(): void
	{
		$broken = $this->createStub(ImageFilter::class);
		$broken->method('label')->willThrowException(new \Error('label broke'));
		$registry = $this->filterRegistry();
		$registry->registerExtensionFilter('acme/pack', 'acme-glow', new GuardedImageFilter($broken, testExtensionGuard(), 'acme/pack', 'acme-glow'));
		$action = new AdminImageworksAction($this->renderer, mediaToolAccessAuthOff(), $registry);

		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/imageworks.twig',
				$this->callback(static function (array $data): bool {
					$labels = array_column($data['filters'] ?? [], 'label', 'name');

					return ($labels['acme-glow'] ?? null) === 'acme-glow' && isset($labels['ember']);
				})
			)
			->willReturn($this->response);

		$action($this->request, $this->response);
	}

	public function testDoesNotRequireUrlData(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/imageworks.twig',
				$this->anything()
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testReturnsResponseFromRenderer(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->method('template')->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testAcceptsRequestParameter(): void
	{
		$request          = $this->createMock(ServerRequestInterface::class);
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->method('template')->willReturn($expectedResponse);

		$result = ($this->action)($request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAcceptsResponseParameter(): void
	{
		$response         = $this->createMock(ResponseInterface::class);
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with($response, 'admin/imageworks.twig', $this->anything())
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $response);

		$this->assertSame($expectedResponse, $result);
	}
}
