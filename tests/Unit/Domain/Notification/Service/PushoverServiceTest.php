<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification\Service;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Notification\Service\PushoverService;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class PushoverServiceTest extends TestCase
{
	private PushoverService $service;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $logger;

	protected function setUp(): void
	{
		$this->twigEngine      = $this->createMock(TwigEngine::class);
		$this->config          = $this->createMock(Config::class);
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->editionFeatures->method('can')->willReturn(true);
		$this->logger          = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$imageGenerator = $this->createMock(ImageGenerator::class);

		$this->service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$this->editionFeatures,
			$imageGenerator,
			$loggerFactory
		);
	}

	public function testReturnsFailureWhenNotConfigured(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => '', 'pushoverUserKey' => ''];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('not configured', $result->message);
	}

	public function testReturnsFailureWhenMissingUserKey(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => 'abc123', 'pushoverUserKey' => ''];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('not configured', $result->message);
	}

	public function testReturnsFailureWhenMissingAppToken(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => '', 'pushoverUserKey' => 'abc123'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('not configured', $result->message);
	}

	public function testBlockedByEditionGating(): void
	{
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')
			->with(EditionFeature::PUSHOVER_ACTIONS)
			->willReturn(false);
		$editionFeatures->method('getEdition')
			->willReturn(\TotalCMS\Domain\License\Data\Edition::LITE);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$imageGenerator = $this->createMock(ImageGenerator::class);

		$service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$editionFeatures,
			$imageGenerator,
			$loggerFactory
		);

		$result = $service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Pro edition', $result->message);
	}

	public function testProcessesTwigInTitleAndMessage(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];

		$this->twigEngine->expects($this->atLeast(2))
			->method('renderString')
			->willReturnCallback(function (string $template, array $data): string {
				if (str_contains($template, '{{ data.name }}')) {
					return str_replace('{{ data.name }}', 'John', $template);
				}

				return $template;
			});

		// The API call will fail with fake credentials, but we can verify Twig was called
		$result = $this->service->send(
			message: '{{ data.name }} submitted a form',
			data: ['name' => 'John'],
			title: 'Hello {{ data.name }}',
		);

		// Will fail at API level (fake creds), but Twig processing was tested
		$this->assertIsBool($result->success);
	}

	public function testHandlesTwigProcessingErrors(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];

		$this->twigEngine->method('renderString')
			->willReturnCallback(function (string $template): string {
				if (str_contains($template, 'invalid')) {
					throw new \Exception('Twig syntax error');
				}

				return $template;
			});

		// Should not throw — gracefully falls back to original template
		$result = $this->service->send(message: 'Valid message', title: '{{ invalid syntax');

		$this->assertIsBool($result->success);
	}

	public function testLogsWarningWhenNotConfigured(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => '', 'pushoverUserKey' => ''];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pushover not configured',
				$this->callback(fn ($context): bool => isset($context['hasToken']) && isset($context['hasUser']))
			);

		$this->service->send(message: 'Hello', title: 'Test');
	}

	public function testLogsEditionBlockWarning(): void
	{
		$editionFeatures = $this->createMock(EditionFeatureService::class);
		$editionFeatures->method('can')
			->with(EditionFeature::PUSHOVER_ACTIONS)
			->willReturn(false);
		$editionFeatures->method('getEdition')
			->willReturn(\TotalCMS\Domain\License\Data\Edition::LITE);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$imageGenerator = $this->createMock(ImageGenerator::class);

		$service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$editionFeatures,
			$imageGenerator,
			$loggerFactory
		);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pushover action blocked by edition',
				$this->callback(fn ($context): bool => isset($context['edition']))
			);

		$service->send(message: 'Hello', title: 'Test');
	}

	public function testGeneratesImageAttachment(): void
	{
		$imageBytes = str_repeat('x', 1024); // fake JPEG bytes

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($imageBytes);

		$imageResponse = $this->createMock(ResponseInterface::class);
		$imageResponse->method('getBody')->willReturn($stream);

		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->expects($this->once())
			->method('generateImage')
			->with('products', 'abc123', 'photo', ['w' => 1920, 'h' => 1920, 'fm' => 'jpg'])
			->willReturn($imageResponse);

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $service->send(
			message: 'New product',
			image: ['collection' => 'products', 'id' => 'abc123', 'property' => 'photo'],
		);

		$this->assertIsBool($result->success);
	}

	public function testGeneratesGalleryImageAttachment(): void
	{
		$imageBytes = str_repeat('x', 1024);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($imageBytes);

		$imageResponse = $this->createMock(ResponseInterface::class);
		$imageResponse->method('getBody')->willReturn($stream);

		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->expects($this->once())
			->method('generateGalleryImage')
			->with('products', 'abc123', 'gallery', 'first', ['w' => 1920, 'h' => 1920, 'fm' => 'jpg'])
			->willReturn($imageResponse);

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $service->send(
			message: 'Gallery updated',
			image: ['collection' => 'products', 'id' => 'abc123', 'property' => 'gallery', 'name' => 'first'],
		);

		$this->assertIsBool($result->success);
	}

	public function testSkipsAttachmentOnImageGenerationFailure(): void
	{
		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->method('generateImage')
			->willThrowException(new \UnexpectedValueException('Invalid image property found'));

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$this->logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				'Pushover image generation failed',
				$this->callback(fn ($ctx): bool => isset($ctx['error']) && isset($ctx['collection']))
			);

		$result = $service->send(
			message: 'Test',
			image: ['collection' => 'products', 'id' => 'abc123', 'property' => 'photo'],
		);

		// Should still attempt to send (without attachment), not throw
		$this->assertIsBool($result->success);
	}

	public function testSkipsAttachmentOnIncompleteImageConfig(): void
	{
		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->expects($this->never())->method('generateImage');
		$imageGenerator->expects($this->never())->method('generateGalleryImage');

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $service->send(
			message: 'Test',
			image: ['collection' => 'products'],
		);

		$this->assertIsBool($result->success);
	}

	public function testSkipsAttachmentWhenImageExceeds5MB(): void
	{
		$oversizedBytes = str_repeat('x', 5242881); // 1 byte over 5MB

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($oversizedBytes);

		$imageResponse = $this->createMock(ResponseInterface::class);
		$imageResponse->method('getBody')->willReturn($stream);

		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->method('generateImage')->willReturn($imageResponse);

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$this->logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				'Pushover image exceeds 5MB limit',
				$this->callback(fn ($ctx): bool => isset($ctx['size']) && $ctx['size'] === 5242881)
			);

		$result = $service->send(
			message: 'Test',
			image: ['collection' => 'products', 'id' => 'abc123', 'property' => 'photo'],
		);

		$this->assertIsBool($result->success);
	}

	public function testProcessesTwigInImageId(): void
	{
		$imageBytes = str_repeat('x', 1024);

		$stream = $this->createMock(StreamInterface::class);
		$stream->method('getContents')->willReturn($imageBytes);

		$imageResponse = $this->createMock(ResponseInterface::class);
		$imageResponse->method('getBody')->willReturn($stream);

		$imageGenerator = $this->createMock(ImageGenerator::class);
		$imageGenerator->expects($this->once())
			->method('generateImage')
			->with('products', 'resolved-id', 'photo', $this->anything())
			->willReturn($imageResponse);

		$this->twigEngine->method('renderString')
			->willReturnCallback(function (string $template): string {
				if ($template === '{{ data.id }}') {
					return 'resolved-id';
				}

				return $template;
			});

		$service = $this->buildServiceWith($imageGenerator);

		$this->config->pushnotif = ['pushoverAppToken' => 'fake-token', 'pushoverUserKey' => 'fake-user'];

		$result = $service->send(
			message: 'Test',
			data: ['id' => 'resolved-id'],
			image: ['collection' => 'products', 'id' => '{{ data.id }}', 'property' => 'photo'],
		);

		$this->assertIsBool($result->success);
	}

	private function buildServiceWith(\PHPUnit\Framework\MockObject\MockObject $imageGenerator): PushoverService
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		return new PushoverService(
			$this->twigEngine,
			$this->config,
			$this->editionFeatures,
			$imageGenerator,
			$loggerFactory
		);
	}
}
