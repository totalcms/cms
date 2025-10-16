<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mailer\Data\MailerData;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;

final class MailerFetcherTest extends TestCase
{
	private MailerFetcher $fetcher;
	private ObjectRepository $objectRepository;

	protected function setUp(): void
	{
		$this->objectRepository = $this->createMock(ObjectRepository::class);
		$this->fetcher          = new MailerFetcher($this->objectRepository);
	}

	public function testFetchMailerReturnsMailerData(): void
	{
		$objectData = $this->createMock(ObjectData::class);
		$objectData->method('toArray')->willReturn($this->getDefaultMailerProperties());

		$this->objectRepository->expects($this->once())
			->method('fetchObject')
			->with('mailer', 'welcome-email')
			->willReturn($objectData);

		$result = $this->fetcher->fetchMailer('welcome-email');

		$this->assertInstanceOf(MailerData::class, $result);
	}

	public function testFetchMailerUsesMailerCollection(): void
	{
		$objectData = $this->createMock(ObjectData::class);
		$objectData->method('toArray')->willReturn($this->getDefaultMailerProperties());

		$this->objectRepository->expects($this->once())
			->method('fetchObject')
			->with(
				$this->equalTo('mailer'),
				$this->anything()
			)
			->willReturn($objectData);

		$this->fetcher->fetchMailer('test-mailer');
	}

	public function testFetchMailerThrowsExceptionWhenNotFound(): void
	{
		$this->objectRepository->expects($this->once())
			->method('fetchObject')
			->with('mailer', 'nonexistent')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Mailer not found: nonexistent');

		$this->fetcher->fetchMailer('nonexistent');
	}

	public function testFetchMailerThrowsExceptionWhenNotObjectData(): void
	{
		$this->objectRepository->expects($this->once())
			->method('fetchObject')
			->with('mailer', 'invalid')
			->willReturn(null);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Mailer not found: invalid');

		$this->fetcher->fetchMailer('invalid');
	}

	public function testExistsReturnsTrueWhenMailerExists(): void
	{
		$this->objectRepository->expects($this->once())
			->method('existsObject')
			->with('mailer', 'welcome-email')
			->willReturn(true);

		$result = $this->fetcher->exists('welcome-email');

		$this->assertTrue($result);
	}

	public function testExistsReturnsFalseWhenMailerDoesNotExist(): void
	{
		$this->objectRepository->expects($this->once())
			->method('existsObject')
			->with('mailer', 'nonexistent')
			->willReturn(false);

		$result = $this->fetcher->exists('nonexistent');

		$this->assertFalse($result);
	}

	public function testExistsUsesMailerCollection(): void
	{
		$this->objectRepository->expects($this->once())
			->method('existsObject')
			->with(
				$this->equalTo('mailer'),
				$this->anything()
			)
			->willReturn(true);

		$this->fetcher->exists('test');
	}

	public function testMultipleFetchMailerCalls(): void
	{
		$mailer1 = $this->createMock(ObjectData::class);
		$mailer1->method('toArray')->willReturn($this->getDefaultMailerProperties());

		$mailer2 = $this->createMock(ObjectData::class);
		$mailer2->method('toArray')->willReturn($this->getDefaultMailerProperties());

		$this->objectRepository->expects($this->exactly(2))
			->method('fetchObject')
			->willReturnOnConsecutiveCalls($mailer1, $mailer2);

		$result1 = $this->fetcher->fetchMailer('mailer-1');
		$result2 = $this->fetcher->fetchMailer('mailer-2');

		$this->assertInstanceOf(MailerData::class, $result1);
		$this->assertInstanceOf(MailerData::class, $result2);
	}

	public function testMultipleExistsCalls(): void
	{
		$this->objectRepository->expects($this->exactly(3))
			->method('existsObject')
			->willReturnOnConsecutiveCalls(true, false, true);

		$this->assertTrue($this->fetcher->exists('exists-1'));
		$this->assertFalse($this->fetcher->exists('nonexistent'));
		$this->assertTrue($this->fetcher->exists('exists-2'));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getDefaultMailerProperties(): array
	{
		return [
			'id'          => 'test-mailer',
			'active'      => true,
			'name'        => 'Test Mailer',
			'description' => 'Test description',
			'from'        => 'from@example.com',
			'fromName'    => 'From Name',
			'to'          => 'to@example.com',
			'toName'      => 'To Name',
			'replyTo'     => 'reply@example.com',
			'cc'          => '',
			'bcc'         => '',
			'subject'     => 'Test Subject',
			'bodyHtml'    => '<p>Test</p>',
			'bodyText'    => 'Test',
		];
	}
}
