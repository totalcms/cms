<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

		$this->service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$this->editionFeatures,
			$loggerFactory
		);
	}

	public function testReturnsFailureWhenNotConfigured(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => '', 'pushoverUserKey' => ''];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);
	}

	public function testReturnsFailureWhenMissingUserKey(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => 'abc123', 'pushoverUserKey' => ''];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);
	}

	public function testReturnsFailureWhenMissingAppToken(): void
	{
		$this->config->pushnotif = ['pushoverAppToken' => '', 'pushoverUserKey' => 'abc123'];
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$result = $this->service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not configured', $result['message']);
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

		$service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$editionFeatures,
			$loggerFactory
		);

		$result = $service->send(message: 'Hello', title: 'Test');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Pro edition', $result['message']);
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
		$this->assertIsBool($result['success']);
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

		$this->assertIsBool($result['success']);
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

		$service = new PushoverService(
			$this->twigEngine,
			$this->config,
			$editionFeatures,
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
}
