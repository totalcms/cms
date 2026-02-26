<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Data\MailerData;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class EmailServiceTest extends TestCase
{
	private EmailService $service;
	private \PHPUnit\Framework\MockObject\MockObject $mailerFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $emailSender;
	private \PHPUnit\Framework\MockObject\MockObject $twigEngine;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;
	private \PHPUnit\Framework\MockObject\MockObject $logger;

	protected function setUp(): void
	{
		$this->mailerFetcher   = $this->createMock(MailerFetcher::class);
		$this->emailSender     = $this->createMock(EmailSender::class);
		$this->twigEngine      = $this->createMock(TwigEngine::class);
		$this->config          = $this->createMock(Config::class);
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		// By default, allow mailer actions (can be overridden in individual tests)
		$this->editionFeatures->method('can')->willReturn(true);
		$this->logger          = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->service = new EmailService(
			$this->mailerFetcher,
			$this->emailSender,
			$this->twigEngine,
			$this->config,
			$this->editionFeatures,
			$loggerFactory
		);
	}

	public function testSendEmailSuccessfully(): void
	{
		$mailerData = $this->createMailerData(active: true);

		$this->mailerFetcher->method('fetchMailer')
			->with('welcome-email')
			->willReturn($mailerData);

		$this->twigEngine->method('renderString')
			->willReturnArgument(0);

		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->willReturn(['success' => true, 'message' => 'Email sent']);

		$result = $this->service->sendEmail('welcome-email', ['name' => 'John']);

		$this->assertTrue($result['success']);
	}

	public function testReturnsFailureWhenMailerInactive(): void
	{
		$mailerData = $this->createMailerData(active: false);

		$this->mailerFetcher->method('fetchMailer')
			->with('inactive-mailer')
			->willReturn($mailerData);

		$this->emailSender->expects($this->never())->method('send');

		$result = $this->service->sendEmail('inactive-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not active', $result['message']);
	}

	public function testProcessesTwigInEmailFields(): void
	{
		$mailerData = $this->createMailerData(
			to: '{{ data.email }}',
			subject: 'Hello {{ data.name }}',
			bodyHtml: '<p>Welcome {{ data.name }}</p>'
		);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);

		$this->twigEngine->expects($this->atLeast(3))
			->method('renderString')
			->willReturnCallback(function ($template, $data) {
				// Simulate basic Twig rendering
				if (str_contains($template, '{{ data.email }}')) {
					return 'user@example.com';
				}
				if (str_contains($template, '{{ data.name }}')) {
					return str_replace('{{ data.name }}', 'John', $template);
				}

				return $template;
			});

		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(fn ($email): bool => $email['to'] === 'user@example.com'
					&& str_contains((string)$email['subject'], 'John')
					&& str_contains((string)$email['bodyHtml'], 'John')))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer', ['name' => 'John', 'email' => 'user@example.com']);
	}

	public function testHandlesTwigProcessingErrors(): void
	{
		$mailerData = $this->createMailerData(subject: '{{ invalid syntax');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);

		$this->twigEngine->method('renderString')
			->willReturnCallback(function ($template) {
				if (str_contains($template, 'invalid syntax')) {
					throw new \Exception('Twig syntax error');
				}

				return $template;
			});

		$this->config->mailer = ['whitelist' => []];

		// Should still attempt to send with original template
		$this->emailSender->method('send')
			->willReturn(['success' => true, 'message' => 'Sent']);

		$result = $this->service->sendEmail('test-mailer');

		$this->assertTrue($result['success']);
	}

	public function testProcessesEmailList(): void
	{
		$mailerData = $this->createMailerData(
			cc: "admin@example.com\nmanager@example.com",
			bcc: 'log@example.com'
		);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(fn ($email): bool => count($email['cc']) === 2
					&& in_array('admin@example.com', $email['cc'])
					&& in_array('manager@example.com', $email['cc'])
					&& count($email['bcc']) === 1
					&& in_array('log@example.com', $email['bcc'])))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testValidatesEmailWhitelist(): void
	{
		$mailerData = $this->createMailerData(to: 'blocked@example.com');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		// Set whitelist to only allow @allowed.com
		$this->config->mailer = ['whitelist' => ['@allowed.com']];

		$this->emailSender->expects($this->never())->method('send');

		$result = $this->service->sendEmail('test-mailer');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('whitelist', $result['message']);
	}

	public function testAllowsEmailWhenWhitelistMatches(): void
	{
		$mailerData = $this->createMailerData(to: 'user@allowed.com');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$this->config->mailer = ['whitelist' => ['@allowed.com']];

		$this->emailSender->expects($this->once())
			->method('send')
			->willReturn(['success' => true, 'message' => 'Sent']);

		$result = $this->service->sendEmail('test-mailer');

		$this->assertTrue($result['success']);
	}

	public function testWhitelistDisabledWhenEmpty(): void
	{
		$mailerData = $this->createMailerData(to: 'any@example.com');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);

		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->willReturn(['success' => true, 'message' => 'Sent']);

		$result = $this->service->sendEmail('test-mailer');

		$this->assertTrue($result['success']);
	}

	public function testHandlesExceptionGracefully(): void
	{
		$this->mailerFetcher->method('fetchMailer')
			->willThrowException(new \Exception('Mailer not found'));

		$result = $this->service->sendEmail('nonexistent');

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('Mailer not found', $result['error']);
	}

	public function testLogsSuccessfulEmail(): void
	{
		$mailerData = $this->createMailerData();

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->method('send')
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Email sent successfully',
				$this->callback(fn ($context): bool => isset($context['mailerId'])
						&& isset($context['to'])
						&& isset($context['subject']))
			);

		$this->service->sendEmail('test-mailer');
	}

	public function testLogsInactiveMailerWarning(): void
	{
		$mailerData = $this->createMailerData(active: false);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Attempted to send email with inactive mailer',
				$this->callback(fn ($context): bool => isset($context['mailerId']))
			);

		$this->service->sendEmail('inactive-mailer');
	}

	public function testLogsErrors(): void
	{
		$this->mailerFetcher->method('fetchMailer')
			->willThrowException(new \Exception('Database error'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Email service error',
				$this->callback(fn ($context): bool => isset($context['mailerId'])
						&& isset($context['error'])
						&& isset($context['trace']))
			);

		$this->service->sendEmail('test-mailer');
	}

	public function testSkipsEmptyOptionalFields(): void
	{
		$mailerData = $this->createMailerData(
			from: '',
			fromName: '',
			replyTo: '',
			bodyText: ''
		);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(fn ($email): bool => $email['from'] === ''
					&& $email['fromName'] === ''
					&& $email['replyTo'] === ''
					&& $email['bodyText'] === ''))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testPassesDataToTwig(): void
	{
		$mailerData = $this->createMailerData(subject: 'Order {{ data.orderId }}');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);

		$this->twigEngine->expects($this->atLeastOnce())
			->method('renderString')
			->with(
				$this->anything(),
				$this->callback(fn ($twigData): bool => isset($twigData['data'])
						&& $twigData['data']['orderId'] === '12345')
			)
			->willReturnArgument(0);

		$this->config->mailer = ['whitelist' => []];
		$this->emailSender->method('send')->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer', ['orderId' => '12345']);
	}

	// ── Inky Processing Tests ──

	public function testInkyTransformsRowAndColumnsToTable(): void
	{
		$inkyHtml = '<row><columns small="12"><p>Hello World</p></columns></row>';
		$mailerData = $this->createMailerData(bodyHtml: $inkyHtml);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$html = $email['bodyHtml'];
				// Inky should convert <row>/<columns> into table-based layout
				$this->assertStringContainsString('<table', $html);
				$this->assertStringContainsString('<th', $html);
				$this->assertStringContainsString('Hello World', $html);
				// Original Inky tags should be gone
				$this->assertStringNotContainsString('<row>', $html);
				$this->assertStringNotContainsString('<columns', $html);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testInkyTransformsButtonToTable(): void
	{
		$inkyHtml = '<button href="https://example.com">Click Me</button>';
		$mailerData = $this->createMailerData(bodyHtml: $inkyHtml);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$html = $email['bodyHtml'];
				// Inky button should become a table with an anchor
				$this->assertStringContainsString('<table', $html);
				$this->assertStringContainsString('https://example.com', $html);
				$this->assertStringContainsString('Click Me', $html);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testInkyTransformsCallout(): void
	{
		$inkyHtml = '<callout><p>Important notice</p></callout>';
		$mailerData = $this->createMailerData(bodyHtml: $inkyHtml);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$html = $email['bodyHtml'];
				$this->assertStringContainsString('callout', $html);
				$this->assertStringContainsString('Important notice', $html);
				// Should be converted to table structure
				$this->assertStringContainsString('<table', $html);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testPlainHtmlPassesThroughInkyUnchanged(): void
	{
		$plainHtml = '<p>Simple paragraph</p>';
		$mailerData = $this->createMailerData(bodyHtml: $plainHtml);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$html = $email['bodyHtml'];
				$this->assertStringContainsString('Simple paragraph', $html);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testEmptyBodyHtmlSkipsInkyProcessing(): void
	{
		$mailerData = $this->createMailerData(bodyHtml: '');

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$this->assertSame('', $email['bodyHtml']);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer');
	}

	public function testInkyProcessingAfterTwig(): void
	{
		// Twig renders data into Inky markup, then Inky transforms it
		$twigInkyHtml = '<row><columns small="12"><p>{{ data.name }}</p></columns></row>';
		$mailerData = $this->createMailerData(bodyHtml: $twigInkyHtml);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')
			->willReturnCallback(function (string $template): string {
				return str_replace('{{ data.name }}', 'Alice', $template);
			});
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function (array $email): bool {
				$html = $email['bodyHtml'];
				// Twig should have rendered the name
				$this->assertStringContainsString('Alice', $html);
				// Inky should have transformed the layout
				$this->assertStringContainsString('<table', $html);
				$this->assertStringNotContainsString('<row>', $html);
				$this->assertStringNotContainsString('<columns', $html);

				return true;
			}))
			->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer', ['name' => 'Alice']);
	}

	private function createMailerData(
		bool $active = true,
		string $to = 'user@example.com',
		string $toName = 'User',
		string $from = 'noreply@example.com',
		string $fromName = 'System',
		string $replyTo = '',
		string $subject = 'Test Subject',
		string $bodyHtml = '<p>Test</p>',
		string $bodyText = 'Test',
		string $cc = '',
		string $bcc = '',
	): MailerData {
		return new MailerData(
			id: 'test-mailer',
			active: $active,
			name: 'Test Mailer',
			description: 'Test description',
			from: $from,
			fromName: $fromName,
			to: $to,
			toName: $toName,
			replyTo: $replyTo,
			cc: $cc,
			bcc: $bcc,
			subject: $subject,
			bodyHtml: $bodyHtml,
			bodyText: $bodyText,
			bulkCollection: '',
			bulkInclude: '',
			bulkExclude: '',
		);
	}
}
