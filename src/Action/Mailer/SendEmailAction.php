<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * SendEmailAction handles email sending via mailer templates.
 */
readonly class SendEmailAction
{
	public function __construct(
		private EmailService $emailService,
		private JsonRenderer $renderer,
		private AccessManager $accessManager,
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

		// Send email
		$userData = $this->accessManager->userData();
		$result   = $this->emailService->sendEmail($mailerId, $emailData, user: $userData);

		// Set appropriate HTTP status code
		if (!$result['success']) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, $result);
	}
}
