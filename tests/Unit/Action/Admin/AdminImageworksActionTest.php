<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\AdminImageworksAction;
use Psr\Log\NullLogger;
use ReflectionClass;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
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

		$this->action = new AdminImageworksAction($this->renderer, mediaToolAccessAuthOff(), $this->filterRegistry(), $this->extensions());
	}

	private function filterRegistry(): ImageFilterRegistry
	{
		$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->imageworks = ['presets' => [], 'defaults' => [], 'filters' => []];

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		return new ImageFilterRegistry($config, $loggerFactory);
	}

	/**
	 * @param array<string,string> $names extension id => manifest name
	 */
	private function extensions(array $names = []): ExtensionManager
	{
		$manager = $this->createStub(ExtensionManager::class);
		$manager->method('getExtension')->willReturnCallback(
			static fn (string $id): ?array => isset($names[$id]) ? ['name' => $names[$id]] : null,
		);

		return $manager;
	}

	/**
	 * @return list<array{kind:string, title:?string, names:list<string>}>
	 */
	private function rowsRenderedWith(ImageFilterRegistry $registry, ExtensionManager $extensions): array
	{
		$rows = [];
		$this->renderer->method('template')->willReturnCallback(
			function (ResponseInterface $response, string $template, array $data) use (&$rows): ResponseInterface {
				foreach ($data['filterRows'] as $row) {
					$rows[] = ['kind' => $row['kind'], 'title' => $row['title'], 'names' => array_column($row['filters'], 'name')];
				}

				return $response;
			}
		);
		(new AdminImageworksAction($this->renderer, mediaToolAccessAuthOff(), $registry, $extensions))($this->request, $this->response);

		return $rows;
	}

	public function testExtensionLooksGetARowNamedForTheirExtensionAndEffectsStayInExtras(): void
	{
		$registry = $this->filterRegistry();
		$registry->registerExtensionFilters([
			'acme/photo-pack' => [
				['name' => 'acme-kodak', 'filter' => ['label' => 'Kodak', 'steps' => [['sepia', 0.4]]], 'group' => 'look'],
				['name' => 'acme-blueprint', 'filter' => ['label' => 'Blueprint', 'steps' => [['gradient-map', '0a2a5c', 'e8f1ff']]], 'group' => 'duotone'],
				['name' => 'acme-dust', 'filter' => ['label' => 'Dust', 'steps' => [['grain', 0.2]]], 'group' => 'effect'],
			],
			'zeta/looks' => [
				['name' => 'zeta-mono', 'filter' => ['label' => 'Mono', 'steps' => [['grayscale', 1]]], 'group' => 'look'],
			],
		]);

		$rows = $this->rowsRenderedWith($registry, $this->extensions(['acme/photo-pack' => 'Acme Photo Pack']));

		$this->assertSame(['main', 'extension', 'extension', 'extras'], array_column($rows, 'kind'));
		[$main, $acme, $zeta, $extras] = $rows;

		$this->assertNull($main['title']);
		$this->assertContains('ember', $main['names']);
		$this->assertContains('lagoon', $main['names']);
		$this->assertNotContains('acme-kodak', $main['names']);

		$this->assertSame('Acme Photo Pack', $acme['title']);
		$this->assertSame(['acme-kodak', 'acme-blueprint'], $acme['names']);

		// No manifest name to show: the extension id stands in
		$this->assertSame('zeta/looks', $zeta['title']);
		$this->assertSame(['zeta-mono'], $zeta['names']);

		$this->assertContains('vignette', $extras['names']);
		$this->assertContains('acme-dust', $extras['names']);
	}

	public function testSiteConfigLooksStayInTheMainRow(): void
	{
		$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->imageworks = ['presets' => [], 'defaults' => [], 'filters' => ['site-look' => ['label' => 'Site', 'steps' => [['sepia', 0.2]]]]];
		$loggerFactory      = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

		$rows = $this->rowsRenderedWith(new ImageFilterRegistry($config, $loggerFactory), $this->extensions());

		$this->assertSame(['main', 'extras'], array_column($rows, 'kind'));
		$this->assertContains('site-look', $rows[0]['names']);
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
		$action = new AdminImageworksAction($this->renderer, mediaToolAccessAuthOff(), $registry, $this->extensions());

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
