<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Activity\Service\MailActivity;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;

/*
 * queueRenderedSend(): a bulk send whose Twig source the caller supplies
 * (BusinessKit campaigns) rather than a mailer template, and cancelBatch().
 */
beforeEach(function (): void {
	$this->editions = $this->createMock(EditionFeatureService::class);
	$this->editions->method('can')->willReturnCallback(fn (): bool => $this->pro ?? true);
	$this->queuer   = $this->createMock(JobQueuer::class);
	$this->repo     = $this->createMock(BulkMailerRepository::class);
	$this->activity = $this->createMock(MailActivity::class);
	$loggers        = $this->createMock(LoggerFactory::class);
	$loggers->method('channelLogger')->willReturn(new NullLogger());

	$this->service = new BulkMailerService(
		$this->createMock(MailerFetcher::class),
		$this->createMock(IndexFilter::class),
		$this->createMock(ObjectFetcher::class),
		$this->queuer,
		$this->editions,
		$this->createMock(TwigEngine::class),
		$this->repo,
		$this->activity,
		$this->createMock(BulkUnsubscribe::class),
		$loggers,
	);
	$this->message = new RenderedBulkMessage('kit:campaign:c1', '{{ contact.email }}', '{{ contact.name }}', 'Spring news', '<p>Hi</p>', 'Hi');
});

it('stores the message once and queues one rendered job per object', function (): void {
	$stored = null;
	$this->repo->expects($this->once())->method('saveMessage')
		->willReturnCallback(function (string $batchId, array $message) use (&$stored): void {
			$stored = [$batchId, $message];
		});
	$jobs = [];
	$this->queuer->expects($this->exactly(2))->method('queueEmail')
		->willReturnCallback(function (array $data, ?string $at) use (&$jobs): JobData {
			$jobs[] = $data;

			return new JobData();
		});
	$this->repo->expects($this->once())->method('recordBatch')
		->with($this->anything(), 'kit:campaign:c1', 'contacts', 2, 0, null, null);

	$result = $this->service->queueRenderedSend($this->message, 'contacts', ['c1', 'c2']);

	expect($result->success)->toBeTrue()
		->and($result->data['count'])->toBe(2)
		->and($stored[0])->toBe($result->data['batchId'])
		->and(RenderedBulkMessage::fromArray($stored[1]))->toEqual($this->message)
		->and(array_column($jobs, 'objectId'))->toBe(['c1', 'c2'])
		->and(array_unique(array_column($jobs, 'kind')))->toBe(['rendered'])
		->and(array_unique(array_column($jobs, 'mailerId')))->toBe(['kit:campaign:c1'])
		->and(array_unique(array_column($jobs, 'batchId')))->toBe([$result->data['batchId']]);
});

it('leaves out objects that already received this source', function (): void {
	$this->repo->expects($this->once())->method('fetchDeliveredObjectIds')->with('kit:campaign:c1')->willReturn(['c1' => true]);
	$this->queuer->expects($this->once())->method('queueEmail')->with($this->callback(fn (array $d): bool => $d['objectId'] === 'c2'));

	$result = $this->service->queueRenderedSend($this->message, 'contacts', ['c1', 'c2', 'c2']);

	expect($result->data['count'])->toBe(1)->and($result->data['excluded'])->toBe(1);
});

it('queues nothing when every object already received it', function (): void {
	$this->repo->method('fetchDeliveredObjectIds')->willReturn(['c1' => true]);
	$this->repo->expects($this->never())->method('saveMessage');
	$this->queuer->expects($this->never())->method('queueEmail');

	expect($this->service->queueRenderedSend($this->message, 'contacts', ['c1'])->success)->toBeFalse();
});

it('does not record template activity, which has no template to point at', function (): void {
	$this->activity->expects($this->never())->method('bulkQueued');

	$this->service->queueRenderedSend($this->message, 'contacts', ['c1']);
});

it('refuses a message that is not ready to queue', function (): void {
	$message = new RenderedBulkMessage('nocolon', '{{ contact.email }}', '', 'S', '<p>x</p>', '');
	$this->queuer->expects($this->never())->method('queueEmail');

	$result = $this->service->queueRenderedSend($message, 'contacts', ['c1']);

	expect($result->success)->toBeFalse()->and($result->message)->toContain('sourceId');
});

it('needs a collection and at least one object', function (string $collection, array $ids): void {
	expect($this->service->queueRenderedSend($this->message, $collection, $ids)->success)->toBeFalse();
})->with([['', ['c1']], ['contacts', []], ['contacts', ['', '  ']]]);

it('requires the Pro edition', function (): void {
	$this->pro = false;
	$this->queuer->expects($this->never())->method('queueEmail');

	expect($this->service->queueRenderedSend($this->message, 'contacts', ['c1'])->success)->toBeFalse();
});

it('cancels the pending jobs of a batch and records how many', function (): void {
	$this->queuer->expects($this->once())->method('cancelEmailBatch')->with('bulk_1')->willReturn(3);
	$this->repo->expects($this->once())->method('recordCancelled')->with('bulk_1', 3);

	$result = $this->service->cancelBatch('bulk_1');

	expect($result->success)->toBeTrue()->and($result->data['cancelled'])->toBe(3);
});

it('reports a batch with nothing left to cancel', function (): void {
	$this->queuer->method('cancelEmailBatch')->willReturn(0);
	$this->repo->expects($this->never())->method('recordCancelled');

	expect($this->service->cancelBatch('bulk_1')->success)->toBeFalse();
});
