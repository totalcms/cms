<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;

/**
 * BulkMailerPreviewAction handles bulk email preview requests.
 */
readonly class BulkMailerPreviewAction
{
	public function __construct(
		private BulkMailerService $bulkMailerService,
		private RawRenderer $renderer,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		$mailerId   = isset($data['mailerId']) && $data['mailerId'] !== '' ? (string)$data['mailerId'] : '';
		$objectId   = isset($data['bulkPreviewObjectId']) && $data['bulkPreviewObjectId'] !== '' ? (string)$data['bulkPreviewObjectId'] : '';
		$collection = isset($data['bulkCollection']) && $data['bulkCollection'] !== '' ? (string)$data['bulkCollection'] : '';

		if ($mailerId === '') {
			return $this->htmlResponse($response, '<span class="cms-error">mailerId is required</span>');
		}

		if ($objectId === '') {
			return $this->htmlResponse($response, '<span class="cms-error">objectId is required</span>');
		}

		if ($collection === '') {
			return $this->htmlResponse($response, '<span class="cms-error">Please set a Collection in the Audience section</span>');
		}

		$result = $this->bulkMailerService->previewEmail($mailerId, $objectId, $collection);

		if (!$result->success) {
			$message = htmlspecialchars($result->message ?: 'Preview failed');

			return $this->htmlResponse($response, '<span class="cms-error">' . $message . '</span>');
		}

		$subject = htmlspecialchars((string)($result->data['subject'] ?? ''));
		$to      = htmlspecialchars((string)($result->data['to'] ?? ''));
		$srcdoc  = htmlspecialchars((string)($result->data['html'] ?? ''));

		$html = '<div class="bulk-preview-meta"><strong>Subject:</strong> ' . $subject .
			'<br><strong>To:</strong> ' . $to . '</div>' .
			'<iframe class="bulk-preview-frame" sandbox="allow-same-origin" srcdoc="' . $srcdoc . '"></iframe>';

		return $this->htmlResponse($response, $html);
	}

	private function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
	{
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->renderer->render($response, $html);
	}
}
