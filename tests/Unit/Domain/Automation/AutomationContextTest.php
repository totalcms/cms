<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Automation;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Automation\Data\AutomationContext;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;

final class AutomationContextTest extends TestCase
{
	public function testExposesServicesAndPerTriggerPayloadSlots(): void
	{
		$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

		$ctx = new AutomationContext(
			indexReader: $this->createMock(IndexReader::class),
			objectFetcher: $this->createMock(ObjectFetcher::class),
			objectSaver: $this->createMock(ObjectSaver::class),
			objectUpdater: $this->createMock(ObjectUpdater::class),
			objectRemover: $this->createMock(ObjectRemover::class),
			mailer: $this->createMock(EmailService::class),
			config: $config,
			logger: new \Psr\Log\NullLogger(),
			trigger: ['type' => 'schedule', 'cron' => '0 1 * * *'],
			args: ['foo' => 'bar'],
		);

		expect($ctx->trigger['type'])->toBe('schedule');
		expect($ctx->args['foo'])->toBe('bar');
		expect($ctx->request)->toBeNull();
		expect($ctx->event)->toBeNull();
	}
}
