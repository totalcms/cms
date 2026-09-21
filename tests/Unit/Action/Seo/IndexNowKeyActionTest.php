<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Seo;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Seo\IndexNowKeyAction;
use TotalCMS\Domain\Seo\Data\SeoSettings;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;

/**
 * /{key}.txt is how the IndexNow engines verify a submission. It must serve
 * the key only when the request IS the configured key with the feature on;
 * every other case is a plain 404 so nothing about the key leaks and a site
 * with the feature off looks like one that never had it.
 */
final class IndexNowKeyActionTest extends TestCase
{
	private const KEY = 'abcdef0123456789abcdef0123456789';

	/** @param array<string,mixed> $settings */
	private function action(array $settings): IndexNowKeyAction
	{
		$loader = $this->createMock(SeoSettingsLoader::class);
		$loader->method('load')->willReturn(SeoSettings::fromArray($settings, 'example.com'));

		return new IndexNowKeyAction($loader);
	}

	public function testServesTheKeyAsPlainTextWhenEnabledAndTheKeyMatches(): void
	{
		$factory  = new Psr17Factory();
		$response = $this->action(['indexNowEnabled' => true, 'indexNowKey' => self::KEY])(
			$factory->createServerRequest('GET', '/' . self::KEY . '.txt'),
			$factory->createResponse(),
			['key' => self::KEY],
		);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
		$this->assertSame(self::KEY, (string)$response->getBody());
		$this->assertStringContainsString('max-age=86400', $response->getHeaderLine('Cache-Control'));
	}

	public function testAnotherKeyShapedPathIs404EvenWhenEnabled(): void
	{
		$factory = new Psr17Factory();

		$this->expectException(HttpNotFoundException::class);

		$this->action(['indexNowEnabled' => true, 'indexNowKey' => self::KEY])(
			$factory->createServerRequest('GET', '/ffffffffffffffffffffffffffffffff.txt'),
			$factory->createResponse(),
			['key' => 'ffffffffffffffffffffffffffffffff'],
		);
	}

	public function testTheRealKeyIs404WhileTheFeatureIsOff(): void
	{
		$factory = new Psr17Factory();

		$this->expectException(HttpNotFoundException::class);

		$this->action(['indexNowEnabled' => false, 'indexNowKey' => self::KEY])(
			$factory->createServerRequest('GET', '/' . self::KEY . '.txt'),
			$factory->createResponse(),
			['key' => self::KEY],
		);
	}

	public function testEnabledWithNoKeyYetIs404(): void
	{
		// The key autogenerates on the first form save with the toggle on;
		// until then there is nothing to serve and nothing to verify.
		$factory = new Psr17Factory();

		$this->expectException(HttpNotFoundException::class);

		$this->action(['indexNowEnabled' => true])(
			$factory->createServerRequest('GET', '/' . self::KEY . '.txt'),
			$factory->createResponse(),
			['key' => self::KEY],
		);
	}
}
