<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Middleware\License\AccessGroupsEditionMiddleware;
use TotalCMS\Middleware\License\ApiKeysEditionMiddleware;
use TotalCMS\Middleware\License\DataViewsEditionMiddleware;
use TotalCMS\Middleware\License\MailerEditionMiddleware;
use TotalCMS\Middleware\License\RssImportEditionMiddleware;
use TotalCMS\Middleware\License\TemplatesEditionMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

final class EditionMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $twigRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $responseFactory;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private Config $config;

	protected function setUp(): void
	{
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->twigRenderer    = $this->createMock(TwigRenderer::class);
		$this->jsonRenderer    = $this->createMock(JsonRenderer::class);
		$this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->handler         = $this->createMock(RequestHandlerInterface::class);
		$this->config          = $this->createConfigWithEnv('prod');
	}

	private function createConfigWithEnv(string $env): Config
	{
		$config      = $this->createMock(Config::class);
		$config->env = $env;

		/** @var Config $config */
		return $config;
	}

	/**
	 * Create a request mock with a given path.
	 */
	private function mockRequestWithPath(string $path): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn($path);
		$this->request->method('getUri')->willReturn($uri);
		$this->request->method('getHeaderLine')->with('Referer')->willReturn('');
	}

	/**
	 * Set up a 403 response mock chain.
	 */
	private function mock403Response(): ResponseInterface
	{
		$forbiddenResponse = $this->createMock(ResponseInterface::class);
		$baseResponse      = $this->createMock(ResponseInterface::class);

		$this->responseFactory->method('createResponse')->willReturn($baseResponse);
		$baseResponse->method('withStatus')->with(403)->willReturn($forbiddenResponse);

		return $forbiddenResponse;
	}

	// ------------------------------------------------------------------
	// Tests: feature allowed → passes through to handler
	// ------------------------------------------------------------------

	public function testAccessGroupsAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::ACCESS_GROUPS)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new AccessGroupsEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	public function testApiKeysAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::API_KEYS)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new ApiKeysEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	public function testDataViewsAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::DATA_VIEWS)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new DataViewsEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	public function testMailerAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::MAILER_ACTIONS)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new MailerEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	public function testTemplatesAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::TEMPLATES)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new TemplatesEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	public function testRssImportAllowsWhenFeatureEnabled(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::RSS_IMPORT)->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

		$middleware = new RssImportEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($handlerResponse, $result);
	}

	// ------------------------------------------------------------------
	// Tests: feature blocked → returns JSON 403 for API routes
	// ------------------------------------------------------------------

	public function testBlockedFeatureReturnsJson403ForApiRoute(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::API_KEYS)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::LITE);
		$this->mockRequestWithPath('/api-keys');

		$forbiddenResponse = $this->mock403Response();

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($forbiddenResponse, $this->callback(fn (array $data): bool => isset($data['error']['message'])
					&& str_contains($data['error']['message'], 'API Keys')
					&& str_contains($data['error']['message'], 'Pro')))
			->willReturn($jsonResponse);

		$this->handler->expects($this->never())->method('handle');

		$middleware = new ApiKeysEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($jsonResponse, $result);
	}

	// ------------------------------------------------------------------
	// Tests: feature blocked → returns HTML 403 for admin routes
	// ------------------------------------------------------------------

	public function testBlockedFeatureReturnsHtml403ForAdminRoute(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::TEMPLATES)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::LITE);
		$this->mockRequestWithPath('/admin/templates');

		$forbiddenResponse = $this->mock403Response();

		$twigResponse = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$forbiddenResponse,
				'access-denied.twig',
				$this->callback(fn (array $data): bool => isset($data['message'])
						&& str_contains($data['message'], 'Templates')
						&& $data['details'] === null)
			)
			->willReturn($twigResponse);

		$this->handler->expects($this->never())->method('handle');

		$middleware = new TemplatesEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($twigResponse, $result);
	}

	// ------------------------------------------------------------------
	// Tests: dev mode includes extra details in admin response
	// ------------------------------------------------------------------

	public function testDevModeIncludesDetailsInAdminResponse(): void
	{
		$this->config = $this->createConfigWithEnv('dev');

		$this->editionFeatures->method('can')->with(EditionFeature::MAILER_ACTIONS)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::LITE);
		$this->mockRequestWithPath('/admin/mail');

		$forbiddenResponse = $this->mock403Response();

		$twigResponse = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$forbiddenResponse,
				'access-denied.twig',
				$this->callback(
					// Dev mode: message includes current edition, details includes path
					fn (array $data): bool => isset($data['message'], $data['details'])
						&& str_contains($data['message'], 'Lite')
						&& str_contains($data['details'], '/admin/mail')
						&& str_contains($data['details'], 'Lite')
				)
			)
			->willReturn($twigResponse);

		$middleware = new MailerEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($twigResponse, $result);
	}

	public function testProdModeOmitsDetailsInAdminResponse(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::ACCESS_GROUPS)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::LITE);
		$this->mockRequestWithPath('/admin/utils/access-groups');

		$forbiddenResponse = $this->mock403Response();

		$twigResponse = $this->createMock(ResponseInterface::class);
		$this->twigRenderer->expects($this->once())
			->method('template')
			->with(
				$forbiddenResponse,
				'access-denied.twig',
				$this->callback(fn (array $data): bool => $data['details'] === null)
			)
			->willReturn($twigResponse);

		$middleware = new AccessGroupsEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$result = $middleware->process($this->request, $this->handler);
		$this->assertSame($twigResponse, $result);
	}

	// ------------------------------------------------------------------
	// Tests: error message formatting
	// ------------------------------------------------------------------

	public function testStandardFeatureMessageFormat(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::RSS_IMPORT)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::LITE);
		$this->mockRequestWithPath('/rss-import');

		$forbiddenResponse = $this->mock403Response();

		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($forbiddenResponse, $this->callback(function (array $data): bool {
				$msg = $data['error']['message'] ?? '';

				return str_contains($msg, '"RSS Import"')
					&& str_contains($msg, 'Standard edition or higher')
					&& !str_contains($msg, 'Current edition');
			}))
			->willReturn($this->createMock(ResponseInterface::class));

		$middleware = new RssImportEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$middleware->process($this->request, $this->handler);
	}

	public function testProFeatureMessageFormat(): void
	{
		$this->editionFeatures->method('can')->with(EditionFeature::DATA_VIEWS)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::STANDARD);
		$this->mockRequestWithPath('/data-views');

		$forbiddenResponse = $this->mock403Response();

		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($forbiddenResponse, $this->callback(function (array $data): bool {
				$msg = $data['error']['message'] ?? '';

				return str_contains($msg, '"Data Views"')
					&& str_contains($msg, 'Pro edition or higher')
					&& !str_contains($msg, 'Current edition');
			}))
			->willReturn($this->createMock(ResponseInterface::class));

		$middleware = new DataViewsEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$middleware->process($this->request, $this->handler);
	}

	public function testDevModeMessageIncludesCurrentEdition(): void
	{
		$this->config = $this->createConfigWithEnv('dev');

		$this->editionFeatures->method('can')->with(EditionFeature::API_KEYS)->willReturn(false);
		$this->editionFeatures->method('getEdition')->willReturn(Edition::STANDARD);
		$this->mockRequestWithPath('/api-keys');

		$forbiddenResponse = $this->mock403Response();

		$this->jsonRenderer->expects($this->once())
			->method('json')
			->with($forbiddenResponse, $this->callback(function (array $data): bool {
				$msg = $data['error']['message'] ?? '';

				return str_contains($msg, 'Current edition: Standard');
			}))
			->willReturn($this->createMock(ResponseInterface::class));

		$middleware = new ApiKeysEditionMiddleware(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$middleware->process($this->request, $this->handler);
	}

	// ------------------------------------------------------------------
	// Tests: each middleware checks the correct feature
	// ------------------------------------------------------------------

	/**
	 * @dataProvider middlewareFeatureProvider
	 */
	public function testEachMiddlewareChecksCorrectFeature(string $middlewareClass, EditionFeature $expectedFeature): void
	{
		$this->editionFeatures->expects($this->once())
			->method('can')
			->with($expectedFeature)
			->willReturn(true);

		$handlerResponse = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($handlerResponse);

		$middleware = new $middlewareClass(
			$this->editionFeatures,
			$this->twigRenderer,
			$this->jsonRenderer,
			$this->responseFactory,
			$this->config
		);

		$middleware->process($this->request, $this->handler);
	}

	/**
	 * @return array<string, array{string, EditionFeature}>
	 */
	public static function middlewareFeatureProvider(): array
	{
		return [
			'access groups' => [AccessGroupsEditionMiddleware::class, EditionFeature::ACCESS_GROUPS],
			'api keys'      => [ApiKeysEditionMiddleware::class, EditionFeature::API_KEYS],
			'data views'    => [DataViewsEditionMiddleware::class, EditionFeature::DATA_VIEWS],
			'mailer'        => [MailerEditionMiddleware::class, EditionFeature::MAILER_ACTIONS],
			'templates'     => [TemplatesEditionMiddleware::class, EditionFeature::TEMPLATES],
			'rss import'    => [RssImportEditionMiddleware::class, EditionFeature::RSS_IMPORT],
		];
	}
}
