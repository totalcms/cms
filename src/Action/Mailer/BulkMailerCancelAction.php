<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;

/**
 * BulkMailerCancelAction backs the Cancel button in Send History: it calls
 * off the emails of a batch that have not gone out yet. The batch must
 * belong to the mailer the request names.
 */
readonly class BulkMailerCancelAction
{
	public function __construct(
		private BulkMailerService $bulkMailerService,
		private RawRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data     = (array)$request->getParsedBody();
		$batchId  = trim((string)($data['batchId'] ?? ''));
		$mailerId = trim((string)($data['mailerId'] ?? ''));

		if ($batchId === '' || $mailerId === '') {
			return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> batchId and mailerId are required</div>');
		}

		$result  = $this->bulkMailerService->cancelBatch($batchId, $mailerId);
		$message = htmlspecialchars($result->message);

		if (!$result->success) {
			return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> ' . $message . '</div>');
		}

		// Reloads the Send History panel, which then shows the batch as cancelled
		return $this->htmlResponse($response->withHeader('HX-Trigger', 'bulk-send-cancelled'), '<div class="cms-success">' . $message . '</div>');
	}

	private function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
	{
		return $this->renderer->render($response->withHeader('Content-Type', 'text/html'), $html);
	}
}
