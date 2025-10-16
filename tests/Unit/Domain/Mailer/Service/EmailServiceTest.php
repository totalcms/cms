<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
	private MailerFetcher $mailerFetcher;
	private EmailSender $emailSender;
	private TwigEngine $twigEngine;
	private Config $config;
	private LoggerInterface $logger;

	protected function setUp(): void
	{
		$this->mailerFetcher = $this->createMock(MailerFetcher::class);
		$this->emailSender   = $this->createMock(EmailSender::class);
		$this->twigEngine    = $this->createMock(TwigEngine::class);
		$this->config        = $this->createMock(Config::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->logger);

		$this->service = new EmailService(
			$this->mailerFetcher,
			$this->emailSender,
			$this->twigEngine,
			$this->config,
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
			->with($this->callback(function ($email) {
				return $email['to'] === 'user@example.com'
					&& str_contains($email['subject'], 'John')
					&& str_contains($email['bodyHtml'], 'John');
			}))
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
			bcc: "log@example.com"
		);

		$this->mailerFetcher->method('fetchMailer')->willReturn($mailerData);
		$this->twigEngine->method('renderString')->willReturnArgument(0);
		$this->config->mailer = ['whitelist' => []];

		$this->emailSender->expects($this->once())
			->method('send')
			->with($this->callback(function ($email) {
				return count($email['cc']) === 2
					&& in_array('admin@example.com', $email['cc'])
					&& in_array('manager@example.com', $email['cc'])
					&& count($email['bcc']) === 1
					&& in_array('log@example.com', $email['bcc']);
			}))
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
				$this->callback(function ($context) {
					return isset($context['mailerId'])
						&& isset($context['to'])
						&& isset($context['subject']);
				})
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
				$this->callback(function ($context) {
					return isset($context['mailerId']);
				})
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
				$this->callback(function ($context) {
					return isset($context['mailerId'])
						&& isset($context['error'])
						&& isset($context['trace']);
				})
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
			->with($this->callback(function ($email) {
				return $email['from'] === ''
					&& $email['fromName'] === ''
					&& $email['replyTo'] === ''
					&& $email['bodyText'] === '';
			}))
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
				$this->callback(function ($twigData) {
					return isset($twigData['data'])
						&& $twigData['data']['orderId'] === '12345';
				})
			)
			->willReturnArgument(0);

		$this->config->mailer = ['whitelist' => []];
		$this->emailSender->method('send')->willReturn(['success' => true, 'message' => 'Sent']);

		$this->service->sendEmail('test-mailer', ['orderId' => '12345']);
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
		string $bcc = ''
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
			bodyText: $bodyText
		);
	}
}
