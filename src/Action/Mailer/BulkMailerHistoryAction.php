<?php

declare(strict_types=1);

namespace TotalCMS\Action\Mailer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Mailer\Data\BulkBatchSummaryData;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * BulkMailerHistoryAction renders a mailer's recent bulk send batches with
 * their sent / failed / skipped / pending counts, for the Send History panel.
 * A batch with emails still waiting gets a Cancel button (BulkMailerCancelAction).
 */
readonly class BulkMailerHistoryAction
{
	private const LIMIT = 10;

	public function __construct(
		private BulkMailerRepository $bulkMailerRepository,
		private RawRenderer $renderer,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params   = $request->getQueryParams();
		$mailerId = isset($params['mailerId']) ? (string)$params['mailerId'] : '';

		if ($mailerId === '') {
			return $this->htmlResponse($response, '<div class="cms-error"><strong>Error:</strong> mailerId is required</div>');
		}

		$batches = $this->bulkMailerRepository->fetchMailerBatches($mailerId, self::LIMIT);

		if ($batches === []) {
			return $this->htmlResponse($response, '<p class="bulk-history-empty">This email has not been bulk sent yet.</p>');
		}

		$rows = '';
		foreach ($batches as $batch) {
			$rows .= $this->row($batch, $mailerId);
		}

		$html = '<table class="admin-table bulk-history-table"><thead><tr>' .
			'<th>Queued</th><th>Audience</th><th>Status</th>' .
			'<th>Sent</th><th>Failed</th><th>Skipped</th><th>Pending</th>' .
			'</tr></thead><tbody>' . $rows . '</tbody></table>';

		return $this->htmlResponse($response, $html);
	}

	private function row(BulkBatchSummaryData $batch, string $mailerId): string
	{
		$audience = '<strong>' . htmlspecialchars($batch->collection) . '</strong>';
		if ($batch->isTest()) {
			$audience .= ' <span class="dash-badge sm accent">Test</span><br><small>to ' . htmlspecialchars($batch->overrideTo) . '</small>';
		}
		if ($batch->excluded > 0) {
			$audience .= '<br><small>' . $batch->excluded . ' left out: already received</small>';
		}

		$pending = $batch->pending();

		return '<tr title="Batch ' . htmlspecialchars($batch->batchId) . '">' .
			'<td>' . htmlspecialchars($this->formatUtc($batch->startedAt)) . '</td>' .
			'<td>' . $audience . '</td>' .
			'<td>' . $this->status($batch) . $this->cancelButton($batch->batchId, $mailerId, $pending) . '</td>' .
			'<td>' . $batch->sent . '</td>' .
			'<td>' . $batch->failed . '</td>' .
			'<td>' . $batch->skipped . '</td>' .
			'<td>' . ($pending ?? '–') . '</td>' .
			'</tr>';
	}

	private function status(BulkBatchSummaryData $batch): string
	{
		$pending = $batch->pending();

		if ($pending !== null && $pending > 0) {
			$processed = $batch->sent + $batch->failed + $batch->skipped;
			if ($processed === 0 && $batch->scheduledAt !== '') {
				return '<span class="dash-badge sm muted">Scheduled</span><br><small>' . htmlspecialchars($this->formatUtc($batch->scheduledAt)) . '</small>';
			}

			return '<span class="dash-badge sm muted">In progress</span>';
		}

		if ($batch->cancelled > 0) {
			return '<span class="dash-badge sm warning">Cancelled</span><br><small>' . $batch->cancelled . ' cancelled</small>';
		}

		if ($batch->failed > 0) {
			return '<span class="dash-badge sm danger">Failures</span>';
		}

		if ($batch->sent === 0 && $batch->skipped > 0) {
			return '<span class="dash-badge sm warning">Nothing sent</span>';
		}

		return '<span class="dash-badge sm success">Complete</span>';
	}

	/**
	 * Calls off the emails not sent yet. The cell shows an error in place;
	 * on success the whole panel reloads (bulk-send-cancelled).
	 */
	private function cancelButton(string $batchId, string $mailerId, ?int $pending): string
	{
		if ($pending === null || $pending <= 0) {
			return '';
		}

		$attrs = [
			'type'       => 'button',
			'class'      => 'dash-button sm bulk-history-cancel',
			'hx-post'    => $this->config->api . '/api/action/mailer/bulk/cancel',
			'hx-vals'    => (string)json_encode(['batchId' => $batchId, 'mailerId' => $mailerId]),
			'hx-confirm' => sprintf('Cancel the %d %s not sent yet? Emails already sent stay sent.', $pending, $pending === 1 ? 'email' : 'emails'),
			'hx-target'  => 'closest td',
			'hx-swap'    => 'innerHTML',
		];

		$html = '';
		foreach ($attrs as $name => $value) {
			$html .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
		}

		return '<br><button' . $html . '>Cancel</button>';
	}

	/**
	 * SQLite's CURRENT_TIMESTAMP is UTC; show it in the site's timezone.
	 */
	private function formatUtc(string $timestamp): string
	{
		if ($timestamp === '') {
			return '';
		}

		try {
			$date = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));

			return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y g:i A');
		} catch (\Exception) {
			return $timestamp;
		}
	}

	private function htmlResponse(ResponseInterface $response, string $html): ResponseInterface
	{
		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->renderer->render($response, $html);
	}
}
