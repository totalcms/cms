<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Action\Mailer\BulkMailerHistoryAction;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

// The Send History panel is how an operator finds out what a bulk send
// actually did — a batch that was queued but skipped everything must say so.

function bulkHistoryRender(BulkMailerRepository $repository, string $mailerId): string
{
	$request  = (new ServerRequestFactory())->createServerRequest('GET', '/api/action/mailer/bulk/history')
		->withQueryParams(['mailerId' => $mailerId]);
	$response = (new BulkMailerHistoryAction($repository, new RawRenderer()))($request, (new ResponseFactory())->createResponse());

	return (string)$response->getBody();
}

beforeEach(function (): void {
	$this->tmpDir = sys_get_temp_dir() . '/tcms-bulk-history-' . uniqid('', true);
	mkdir($this->tmpDir, 0755, true);
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $this->tmpDir;
	$this->repository = new BulkMailerRepository($config);
});

afterEach(function (): void {
	@unlink($this->tmpDir . '/.system/bulkmailer');
	@rmdir($this->tmpDir . '/.system');
	@rmdir($this->tmpDir);
});

it('says so when the email has never been bulk sent', function (): void {
	expect(bulkHistoryRender($this->repository, 'news'))->toContain('has not been bulk sent yet');
});

it('requires a mailerId', function (): void {
	expect(bulkHistoryRender($this->repository, ''))->toContain('mailerId is required');
});

it('flags a batch that sent nothing because every job was skipped', function (): void {
	$this->repository->recordBatch('b1', 'news', 'members', 2, 0, null, null);
	foreach (['a', 'b'] as $id) {
		$this->repository->log(['batchId' => 'b1', 'mailerId' => 'news', 'collection' => 'members', 'objectId' => $id, 'status' => 'skipped']);
	}

	expect(bulkHistoryRender($this->repository, 'news'))
		->toContain('Nothing sent')
		->toContain('<strong>members</strong>');
});

it('labels a test batch with its override address and escapes it', function (): void {
	$this->repository->recordBatch('b1', 'news', 'members', 1, 0, '<me>@example.com', null);

	expect(bulkHistoryRender($this->repository, 'news'))
		->toContain('Test')
		->toContain('&lt;me&gt;@example.com')
		->toContain('In progress');
});
