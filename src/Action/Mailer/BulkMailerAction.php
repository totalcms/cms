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

		$collection  = isset($data['bulkCollection']) && $data['bulkCollection'] !== '' ? (string)$data['bulkCollection'] : '';
		$include     = isset($data['bulkInclude']) && $data['bulkInclude'] !== '' ? (string)$data['bulkInclude'] : '';
		$exclude     = isset($data['bulkExclude']) && $data['bulkExclude'] !== '' ? (string)$data['bulkExclude'] : '';
		$scheduledAt = isset($data['bulkscheduledAt']) && $data['bulkscheduledAt'] !== '' ? (string)$data['bulkscheduledAt'] : null;
		$overrideTo  = isset($data['bulkOverrideTo']) && $data['bulkOverrideTo'] !== '' ? (string)$data['bulkOverrideTo'] : null;

		$objectIds = null;
		if (isset($data['bulkObjectIds']) && is_array($data['bulkObjectIds'])) {
			$filtered = array_filter(
				array_map(strval(...), $data['bulkObjectIds']),
				static fn (string $v): bool => $v !== ''
			);
			if ($filtered !== []) {
				$objectIds = array_values($filtered);
			}
		}

		$result = $this->bulkMailerService->queueBulkSend($mailerId, $collection, $include, $exclude, $scheduledAt, $overrideTo, $objectIds);

		if ($result->success) {
			$message = htmlspecialchars($result->message);
			$batchId = htmlspecialchars((string)($result->data['batchId'] ?? ''));

			$html = '<div class="cms-success"><strong>Queued!</strong> ' . $message .
				'<br>Batch ID: <code>' . $batchId . '</code></div>';

			return $this->htmlResponse($response, $html);
		}

		$message = htmlspecialchars($result->message);

		return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> ' . $message . '</div>');
	}

	private function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
	{
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->renderer->render($response, $html);
	}
}
