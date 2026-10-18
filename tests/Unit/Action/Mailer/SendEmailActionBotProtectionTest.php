<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Mailer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use TotalCMS\Action\Mailer\SendEmailAction;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mailer\Data\MailerData;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Security\BotProtection;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

/**
 * The public mailer endpoint screens a form's mail like the form's save: the
 * data carries the form's bot fields, checked against the form's collection,
 * and a template with Require Bot Protection refuses mail without them.
 */
final class SendEmailActionBotProtectionTest extends TestCase
{
	private EmailService&MockObject $emailService;
	private MailerFetcher&MockObject $mailers;

	protected function setUp(): void
	{
		$this->emailService = $this->createMock(EmailService::class);
		$this->mailers      = $this->createMock(MailerFetcher::class);
	}

	public function testRequiredTemplateWithoutBotFieldsAnswersSuccessAndSendsNothing(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->never())->method('sendEmail');

		$response = $this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => ['message' => 'hi']]);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(OperationResult::success(EmailSender::SENT_MESSAGE)->toArray(), $this->json($response));
	}

	public function testRequiredTemplateSendsWithAValidTokenAndStripsTheBotFields(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('contact', ['message' => 'hi'])
			->willReturn(OperationResult::success(EmailSender::SENT_MESSAGE));

		$response = $this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => $this->botData(time() - 10)]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testRegisterFormTokenIsAccepted(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->once())->method('sendEmail')->willReturn(OperationResult::success(EmailSender::SENT_MESSAGE));

		$this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => $this->botData(time() - 10, formType: 'register')]);
	}

	public function testTokenForAnotherCollectionIsTurnedAway(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->never())->method('sendEmail');

		$response = $this->send(['mailerId' => 'contact', 'collection' => 'other', 'data' => $this->botData(time() - 10)]);

		$this->assertSame(OperationResult::success(EmailSender::SENT_MESSAGE)->toArray(), $this->json($response));
	}

	public function testFilledHoneypotIsTurnedAwayEvenWhenTheTemplateDoesNotRequireIt(): void
	{
		$this->withTemplate(required: false);
		$this->emailService->expects($this->never())->method('sendEmail');

		$response = $this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => $this->botData(time() - 10, honeypot: 'spam')]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testTooFastIsTurnedAway(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->never())->method('sendEmail');

		$this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => $this->botData(time())]);
	}

	public function testExpiredTokenGetsTheVisible422(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->never())->method('sendEmail');

		$response = $this->send(['mailerId' => 'contact', 'collection' => 'messages', 'data' => $this->botData(time() - BotProtection::MAX_AGE_SECONDS - 60)]);

		$this->assertSame(422, $response->getStatusCode());
		$this->assertSame(['error' => ['message' => BotSubmissionGate::EXPIRED_MESSAGE]], $this->json($response));
	}

	public function testTemplateThatDoesNotRequireItSendsPlainData(): void
	{
		$this->withTemplate(required: false);
		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('contact', ['message' => 'hi'])
			->willReturn(OperationResult::success(EmailSender::SENT_MESSAGE));

		$this->send(['mailerId' => 'contact', 'data' => ['message' => 'hi']]);
	}

	public function testSignedInCallerSkipsTheCheck(): void
	{
		$this->withTemplate(required: true);
		$this->emailService->expects($this->once())
			->method('sendEmail')
			->with('contact', ['message' => 'hi'])
			->willReturn(OperationResult::success(EmailSender::SENT_MESSAGE));

		$this->send(['mailerId' => 'contact', 'data' => ['message' => 'hi']], authMethod: 'session');
	}

	public function testUnknownTemplateIsNotRequiredAndReportsTheSendFailure(): void
	{
		$this->mailers->method('exists')->willReturn(false);
		$this->mailers->expects($this->never())->method('fetchMailer');
		$this->emailService->expects($this->once())
			->method('sendEmail')
			->willReturn(OperationResult::failure('Email template not found'));

		$response = $this->send(['mailerId' => 'missing', 'data' => ['message' => 'hi']]);

		$this->assertSame(500, $response->getStatusCode());
	}

	private function withTemplate(bool $required): void
	{
		$this->mailers->method('exists')->with('contact')->willReturn(true);
		$this->mailers->method('fetchMailer')->with('contact')->willReturn(MailerData::fromArray(['id' => 'contact', 'requireBotProtection' => $required]));
	}

	/** @return array<string,mixed> */
	private function botData(int $issuedAt, string $honeypot = '', string $formType = 'create'): array
	{
		return [
			'message'                      => 'hi',
			BotProtection::HONEYPOT_FIELD  => $honeypot,
			BotProtection::TIMESTAMP_FIELD => (new BotProtection('test-key'))->issue('messages', $formType, $issuedAt),
		];
	}

	/** @param array<string,mixed> $body */
	private function send(array $body, ?string $authMethod = null): Response
	{
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn(new NullLogger());
		$apiKeys = $this->createMock(ApiKeyAuthenticator::class);
		$apiKeys->method('hasApiKeyHeader')->willReturn(false);
		$accessManager = $this->createMock(AccessManager::class);
		$accessManager->method('userData')->willReturn([]);

		$action = new SendEmailAction(
			$this->emailService,
			new JsonRenderer(),
			$accessManager,
			new BotSubmissionGate(
				new BotProtection('test-key'),
				$this->createMock(CollectionFetcher::class),
				$this->createMock(ObjectFactory::class),
				$this->createMock(ObjectFetcher::class),
				$apiKeys,
				null,
				$loggerFactory,
			),
			$this->mailers,
		);

		$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/action/mailer')->withParsedBody($body);
		if ($authMethod !== null) {
			$request = $request->withAttribute('authMethod', $authMethod);
		}

		/** @var Response */
		return $action($request, new Response());
	}

	/** @return array<string,mixed> */
	private function json(Response $response): array
	{
		return (array)json_decode((string)$response->getBody(), true);
	}
}
