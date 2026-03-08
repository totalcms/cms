<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Notification\Service\PushoverService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Integration tests that send real Pushover notifications.
 * Requires .env file with PUSHOVER_APP_TOKEN and PUSHOVER_USER_KEY.
 */
final class PushoverIntegrationTest extends TestCase
{
	private PushoverService $service;

	protected function setUp(): void
	{
		$envFile = dirname(__DIR__, 2) . '/.env';
		if (!file_exists($envFile)) {
			$this->markTestSkipped('.env file not found — skipping Pushover integration tests');
		}

		$env = $this->parseEnvFile($envFile);

		$appToken = $env['PUSHOVER_APP_TOKEN'] ?? '';
		$userKey  = $env['PUSHOVER_USER_KEY'] ?? '';

		if ($appToken === '' || $userKey === '') {
			$this->markTestSkipped('PUSHOVER_APP_TOKEN or PUSHOVER_USER_KEY not set in .env');
		}

		$twigEngine      = $this->createMock(TwigEngine::class);
		$twigEngine->method('renderString')->willReturnCallback(
			fn (string $template): string => $template
		);

		$config           = $this->createMock(Config::class);
		$config->pushnotif = ['pushoverAppToken' => $appToken, 'pushoverUserKey' => $userKey];

		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')->willReturn(true);

		$logger        = $this->createMock(LoggerInterface::class);
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($logger);

		$testImage = dirname(__DIR__) . '/test-data/test-image.jpg';
		$jpeg      = file_get_contents($testImage);
		assert($jpeg !== false);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($jpeg);

		$imageResponse = $this->createMock(ResponseInterface::class);
		$imageResponse->method('getBody')->willReturn($stream);

		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->method('generateImage')->willReturn($imageResponse);

		$this->service = new PushoverService(
			$twigEngine,
			$config,
			$editionFeatures,
			$imageGenerator,
			$loggerFactory
		);
	}

	public function testSendBasicNotification(): void
	{
		$result = $this->service->send(
			message: 'Basic notification from PushoverIntegrationTest',
			title: 'Total CMS Test',
		);

		$this->assertTrue($result['success'], 'Failed: ' . ($result['message'] ?? 'unknown'));
		$this->assertSame('Notification sent', $result['message']);
	}

	public function testSendNotificationWithAllOptions(): void
	{
		$result = $this->service->send(
			title: 'Total CMS Full Test',
			message: 'Notification with all options from integration test',
			priority: -1,
			sound: 'cashregister',
			link: 'https://totalcms.co',
			linkTitle: 'Visit Total CMS',
			image: ['collection' => 'test', 'id' => 'test-obj', 'property' => 'photo'],
		);

		$this->assertTrue($result['success'], 'Failed: ' . ($result['message'] ?? 'unknown'));
	}

	public function testSendHighPriorityNotification(): void
	{
		$result = $this->service->send(
			title: 'Total CMS Priority Test',
			message: 'High priority notification test',
			priority: 1,
		);

		$this->assertTrue($result['success'], 'Failed: ' . ($result['message'] ?? 'unknown'));
	}

	/**
	 * Parse a simple .env file into key-value pairs.
	 *
	 * @return array<string,string>
	 */
	private function parseEnvFile(string $path): array
	{
		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$env   = [];

		if ($lines === false) {
			return [];
		}

		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}

			$parts = explode('=', $line, 2);
			if (count($parts) === 2) {
				$env[trim($parts[0])] = trim($parts[1]);
			}
		}

		return $env;
	}
}
