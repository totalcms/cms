<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Security\BotSubmissionGate;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

/**
 * SendEmailAction handles email sending via mailer templates.
 *
 * The endpoint is public: a form's mailer action calls it from the browser
 * after the save. The form's data carries its bot-protection fields, so the
 * mail is screened like the save ({@see BotSubmissionGate::screenMail()}):
 * when the fields are present, or always when the template has Require Bot
 * Protection on. A failed check answers with the same success a real send
 * gives and sends nothing; an expired token gets the visible 422.
 */
readonly class SendEmailAction
{
	public function __construct(
		private EmailService $emailService,
		private JsonRenderer $renderer,
		private AccessManager $accessManager,
		private BotSubmissionGate $botGate,
		private MailerFetcher $mailers,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		// Validate required fields
		if (!isset($data['mailerId']) || $data['mailerId'] === '') {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'mailerId is required',
			]);
		}

		$mailerId  = (string)$data['mailerId'];
		$emailData = $data['data'] ?? [];

		if ($emailData === '') {
			$emailData = [];
		} elseif (is_string($emailData)) {
			$emailData = json_decode($emailData, true);
		}

		// Ensure data is an array
		if (!is_array($emailData)) {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'data must be an array',
			]);
		}

		$collection           = is_string($data['collection'] ?? null) ? $data['collection'] : '';
		[$emailData, $reason] = $this->botGate->screenMail($request, $emailData, $collection, $mailerId, $this->requiresBotProtection($mailerId));
		if (BotSubmissionGate::expired($reason)) {
			return $this->botGate->expiredResponse($request, $response);
		}
		if ($reason !== '') {
			return $this->renderer->json($response, OperationResult::success(EmailSender::SENT_MESSAGE)->toArray());
		}

		// Send email
		$userData = $this->accessManager->userData();
		$result   = $this->emailService->sendEmail($mailerId, $emailData, user: $userData);

		// Set appropriate HTTP status code
		if (!$result->success) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, $result->toArray());
	}

	/** A template that does not exist answers false here; the send reports it. */
	private function requiresBotProtection(string $mailerId): bool
	{
		return $this->mailers->exists($mailerId) && $this->mailers->fetchMailer($mailerId)->requireBotProtection;
	}
}
