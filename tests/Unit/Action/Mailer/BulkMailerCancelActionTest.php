<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Action\Mailer\BulkMailerCancelAction;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\OperationResult;

// The Cancel button in Send History: calls off the emails of a batch that
// have not gone out yet, then reloads the panel.

beforeEach(function (): void {
	$this->service = $this->createMock(BulkMailerService::class);
	$this->cancel  = function (array $body) {
		$request = (new ServerRequestFactory())->createServerRequest('POST', '/api/action/mailer/bulk/cancel')->withParsedBody($body);

		return (new BulkMailerCancelAction($this->service, new RawRenderer()))($request, (new ResponseFactory())->createResponse());
	};
});

it('requires a batch and its mailer', function (): void {
	$this->service->expects($this->never())->method('cancelBatch');

	expect((string)($this->cancel)(['batchId' => 'b1'])->getBody())->toContain('batchId and mailerId are required');
});

it('cancels the batch and reloads Send History', function (): void {
	$this->service->expects($this->once())->method('cancelBatch')->with('b1', 'news')
		->willReturn(OperationResult::success('Cancelled 3 emails', ['batchId' => 'b1', 'cancelled' => 3]));

	$response = ($this->cancel)(['batchId' => 'b1', 'mailerId' => 'news']);

	expect($response->getHeaderLine('HX-Trigger'))->toBe('bulk-send-cancelled')
		->and((string)$response->getBody())->toContain('Cancelled 3 emails');
});

it('shows why nothing was cancelled', function (): void {
	$this->service->method('cancelBatch')->willReturn(OperationResult::failure('Nothing to cancel: <none>'));

	$response = ($this->cancel)(['batchId' => 'b1', 'mailerId' => 'news']);

	expect($response->getHeaderLine('HX-Trigger'))->toBe('')
		->and((string)$response->getBody())->toContain('cms-error')->toContain('&lt;none&gt;');
});
