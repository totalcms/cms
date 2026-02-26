<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;

/**
 * BulkMailerAction handles bulk email sending requests.
 */
readonly class BulkMailerAction
{
	public function __construct(
		private BulkMailerService $bulkMailerService,
		private RawRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		$mailerId = isset($data['mailerId']) && $data['mailerId'] !== '' ? (string)$data['mailerId'] : '';

		if ($mailerId === '') {
			return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> mailerId is required</div>');
		}

		$scheduledAt = isset($data['bulkscheduledAt']) && $data['bulkscheduledAt'] !== '' ? (string)$data['bulkscheduledAt'] : null;
		$overrideTo  = isset($data['bulkOverrideTo']) && $data['bulkOverrideTo'] !== '' ? (string)$data['bulkOverrideTo'] : null;

		$result = $this->bulkMailerService->queueBulkSend($mailerId, $scheduledAt, $overrideTo);

		if ($result['success']) {
			$message = htmlspecialchars($result['message']);
			$batchId = htmlspecialchars((string)($result['batchId'] ?? ''));

			$html = '<div class="cms-success"><strong>Queued!</strong> ' . $message .
				'<br>Batch ID: <code>' . $batchId . '</code></div>';

			return $this->htmlResponse($response, $html);
		}

		$message = htmlspecialchars($result['message']);

		return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> ' . $message . '</div>');
	}

	private function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
	{
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->renderer->render($response, $html);
	}
}
