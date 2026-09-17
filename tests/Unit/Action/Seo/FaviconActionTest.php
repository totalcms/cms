<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Seo\FaviconAction;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

// Browsers and crawlers ask for /favicon.ico whether or not the head lists an
// icon, so the route answers from the Site SEO record's Icon: the 32px PNG
// ImageWorks already makes, wrapped as an ICO. /favicon.svg serves the SVG
// upload inline — the download route would offer it as an attachment.
describe('FaviconAction', function (): void {
	$factory = new Psr17Factory();
	$png     = static function (): string {
		ob_start();
		imagepng(imagecreatetruecolor(32, 32));

		return (string)ob_get_clean();
	};

	// Built inside beforeEach so the closure is bound to the test case and can
	// create PHPUnit mocks.
	beforeEach(function () use ($factory): void {
		$this->action = function (bool $hasIcon, ?string $pngBytes = null, ?string $svgBytes = null) use ($factory): FaviconAction {
		// The touch icon is the Icon standing in, so the route must add the
		// theme color as the background, exactly as the head's link does.
		$settings = SeoSettings::fromArray($hasIcon ? ['icon32' => '/imageworks/x.png', 'touchIconProperty' => 'icon', 'themeColor' => '#090e1b', 'iconSvgUrl' => $svgBytes !== null ? '/favicon.svg' : ''] : [], 'example.com');
		$loader   = $this->createMock(SeoSettingsLoader::class);
		$loader->method('load')->willReturn($settings);

		$images = $this->createMock(ImageGenerator::class);
		$images->method('generateImage')->willReturnCallback(function (string $collection, string $id, string $property, array $params) use ($factory, $pngBytes) {
			expect([$collection, $id])->toBe(['seo-site', 'seo-site']);
			expect([$property, $params])->toBeIn([['icon', SeoSettings::ICON_32], ['icon', SeoSettings::TOUCH_ICON + ['bg' => '090e1b']]]);

			return $factory->createResponse(200)->withBody($factory->createStream((string)$pngBytes));
		});

		$files = $this->createMock(FileFetcher::class);
		$files->method('streamFile')->willReturnCallback(function () use ($svgBytes) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, (string)$svgBytes);
			rewind($stream);

			return $stream;
		});

			return new FaviconAction($loader, $images, $files);
		};
	});

	test('serves the Icon as an ICO with a day of caching', function () use ($factory, $png): void {
		$source   = $png();
		$request  = $factory->createServerRequest('GET', '/favicon.ico');
		$response = (($this->action)(true, $source))($request, $factory->createResponse(), ['format' => 'ico']);

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toBe('image/x-icon')
			->and($response->getHeaderLine('Cache-Control'))->toBe('public, max-age=86400')
			->and(substr((string)$response->getBody(), 22))->toBe($source);
	});

	test('serves the touch icon as a PNG from the property the head links', function () use ($factory, $png): void {
		$source   = $png();
		$request  = $factory->createServerRequest('GET', '/apple-touch-icon.png');
		$response = (($this->action)(true, $source))($request, $factory->createResponse(), ['format' => 'png']);

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toBe('image/png')
			->and($response->getHeaderLine('Cache-Control'))->toBe('public, max-age=86400')
			->and((string)$response->getBody())->toBe($source);
	});

	test('serves the SVG inline', function () use ($factory, $png): void {
		$request  = $factory->createServerRequest('GET', '/favicon.svg');
		$response = (($this->action)(true, $png(), '<svg xmlns="http://www.w3.org/2000/svg"/>'))($request, $factory->createResponse(), ['format' => 'svg']);

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toBe('image/svg+xml')
			->and($response->getHeaderLine('Content-Disposition'))->toBe('inline; filename="favicon.svg"')
			->and((string)$response->getBody())->toStartWith('<svg');
	});

	test('is a 404 when the record has no icon, and for an SVG that was never uploaded', function () use ($factory, $png): void {
		$request = $factory->createServerRequest('GET', '/favicon.ico');
		expect(fn () => (($this->action)(false))($request, $factory->createResponse(), ['format' => 'ico']))->toThrow(HttpNotFoundException::class);
		expect(fn () => (($this->action)(true, $png()))($request, $factory->createResponse(), ['format' => 'svg']))->toThrow(HttpNotFoundException::class);
		expect(fn () => (($this->action)(false))($request, $factory->createResponse(), ['format' => 'png']))->toThrow(HttpNotFoundException::class);
	});
});
