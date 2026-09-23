<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AuthMailer;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/**
 * The reset and verification emails, sent from one place. Three actions used
 * to each build the link, the expiry and the mailer-or-template choice.
 */
final class AuthMailerTest extends TestCase
{
	private EmailService&MockObject $emailService;
	private EmailSender&MockObject $emailSender;
	private TwigEngine&MockObject $twig;
	private UserValidationService&MockObject $users;
	private Config $config;

	protected function setUp(): void
	{
		$this->emailService = $this->createMock(EmailService::class);
		$this->emailSender  = $this->createMock(EmailSender::class);
		$this->twig         = $this->createMock(TwigEngine::class);
		$this->users        = $this->createMock(UserValidationService::class);
		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->url  = 'https://example.com';
		$this->config->api  = '/cms';
		$this->config->auth = ['resetTokenExpiry' => 45, 'verificationTokenExpiry' => 60];
	}

	private function mailer(): AuthMailer
	{
		return new AuthMailer($this->emailService, $this->emailSender, $this->twig, $this->users, $this->config);
	}

	public function testResetUsesTheBuiltInTemplateWithTheUsersNameAndAnAbsoluteLink(): void
	{
		$this->users->method('findUserByEmail')->with('a@b.c', 'members')->willReturn(new ObjectData('joe', ['name' => new StringData('Joe')]));
		$this->twig->expects($this->once())->method('render')
			->with('email/password-reset.twig', ['name' => 'Joe', 'resetUrl' => 'https://example.com/cms/admin/reset-password/tok', 'expiryMinutes' => 45])
			->willReturn('<p>reset</p>');
		$this->emailSender->expects($this->once())->method('send')
			->with(['to' => 'a@b.c', 'toName' => 'Joe', 'subject' => 'Password Reset Request', 'bodyHtml' => '<p>reset</p>'])
			->willReturn(OperationResult::success('sent'));

		$this->assertTrue($this->mailer()->sendPasswordReset('a@b.c', 'tok', 'members')->success);
	}

	public function testResetGoesThroughTheCustomMailerWhenConfigured(): void
	{
		$this->config->auth['forgotPasswordMailerId'] = 'reset-mail';
		$this->users->method('findUserByEmail')->willReturn(null);
		$this->twig->expects($this->never())->method('render');
		$this->emailService->expects($this->once())->method('sendEmail')
			->with('reset-mail', $this->callback(fn (array $v): bool => $v['email'] === 'a@b.c' && $v['name'] === '' && $v['user'] === [] && $v['collection'] === 'members' && $v['expiryMinutes'] === 45))
			->willReturn(OperationResult::success('sent'));

		$this->mailer()->sendPasswordReset('a@b.c', 'tok', 'members');
	}

	public function testVerificationCarriesTheNameItWasGivenAndItsOwnExpiry(): void
	{
		$this->twig->expects($this->once())->method('render')
			->with('email/verify-email.twig', ['name' => 'New User', 'verifyUrl' => 'https://example.com/cms/admin/verify-email/v1', 'expiryMinutes' => 60])
			->willReturn('<p>verify</p>');
		$this->emailSender->expects($this->once())->method('send')
			->with($this->callback(fn (array $m): bool => $m['subject'] === 'Verify Your Email' && $m['toName'] === 'New User'))
			->willReturn(OperationResult::success('sent'));

		$this->mailer()->sendVerification('n@b.c', 'v1', 'members', 'New User');
	}

	public function testATemplateFailureIsAFailedResultNotAnException(): void
	{
		$this->twig->method('render')->willThrowException(new \RuntimeException('template missing'));

		$result = $this->mailer()->sendVerification('n@b.c', 'v1', 'members');

		$this->assertFalse($result->success);
		$this->assertStringContainsString('Failed to send verification email: template missing', $result->message);
	}
}
