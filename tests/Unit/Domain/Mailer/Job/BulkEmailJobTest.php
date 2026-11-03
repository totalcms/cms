<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\Mailer\Job\BulkEmailJob;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Service\BulkUnsubscribe;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\RenderedBulkHooks;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

/*
 * One queued Bulk Send message: deduped per template and object, skipped
 * when the recipient has unsubscribed, sent with one-click unsubscribe
 * headers when the collection supports them.
 */
beforeEach(function (): void {
	$this->email       = $this->createMock(EmailService::class);
	$this->repo        = $this->createMock(BulkMailerRepository::class);
	$this->fetcher     = $this->createMock(ObjectFetcher::class);
	$this->unsubscribe = $this->createMock(BulkUnsubscribe::class);
	$this->hooks       = $this->createMock(RenderedBulkHooks::class);
	$this->stored      = ['id' => 'obj-1', 'email' => 'ann@example.com'];

	$object = $this->createMock(ObjectData::class);
	$object->method('toArray')->willReturnCallback(fn (): array => $this->stored);
	$this->fetcher->method('fetchObject')->willReturnCallback(fn (): ObjectData => $this->stored === null ? throw new RuntimeException('Object not found') : $object);

	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->smtp = [];
	$loggers      = $this->createMock(LoggerFactory::class);
	$loggers->method('channelLogger')->willReturn(new NullLogger());

	$this->run = function (array $payload): void {
		$job          = new JobData();
		$job->type    = JobData::TYPE_EMAIL;
		$job->payload = (string)json_encode($payload + ['mailerId' => 'news', 'objectId' => 'obj-1', 'collection' => 'members', 'batchId' => 'b1']);

		$this->job->run($job);
	};
	$this->job = new BulkEmailJob($this->email, $this->repo, $this->fetcher, $this->unsubscribe, $this->hooks, $config, $loggers);
});

it('skips a real send to an object that already received it', function (): void {
	$this->repo->method('hasBeenSent')->willReturn(true);
	$this->email->expects($this->never())->method('sendEmail');
	$this->repo->expects($this->once())->method('log')->with($this->callback(fn (array $row): bool => $row['status'] === 'skipped'));

	($this->run)([]);
});

it('logs a failure and throws when the object was deleted after queueing', function (): void {
	// Otherwise the batch shows the object as pending forever.
	$this->stored = null;
	$this->email->expects($this->never())->method('sendEmail');
	$this->repo->expects($this->once())->method('log')->with($this->callback(fn (array $row): bool => $row['status'] === 'failed'));

	expect(fn () => ($this->run)([]))->toThrow(RuntimeException::class);
});

it('always sends an override test copy, even after a real delivery', function (): void {
	// Proofing a batch to yourself must never be deduped away.
	$this->repo->expects($this->never())->method('hasBeenSent');
	$this->email->expects($this->once())->method('sendEmail')
		->with('news', $this->anything(), 'me@example.com')
		->willReturn(OperationResult::success());
	$this->repo->expects($this->once())->method('log')
		->with($this->callback(fn (array $row): bool => $row['status'] === 'sent' && $row['sentTo'] === 'me@example.com'));

	($this->run)(['overrideTo' => 'me@example.com']);
});

it('skips a recipient who unsubscribed after the send was queued', function (): void {
	$this->unsubscribe->method('isUnsubscribed')->willReturn(true);
	$this->email->expects($this->never())->method('sendEmail');
	$this->repo->expects($this->once())->method('log')
		->with($this->callback(fn (array $row): bool => $row['status'] === 'skipped' && str_contains((string)($row['error'] ?? ''), 'unsubscribed')));

	($this->run)([]);
});

it('sends the unsubscribe headers and link with a real send', function (): void {
	$headers = ['List-Unsubscribe' => '<https://example.com/admin/unsubscribe/t>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
	$this->unsubscribe->method('headers')->with('members', 'obj-1')->willReturn($headers);
	$this->unsubscribe->method('url')->willReturn('https://example.com/admin/unsubscribe/t');
	$this->email->expects($this->once())->method('sendEmail')
		->with('news', $this->anything(), null, [], false, ['unsubscribeUrl' => 'https://example.com/admin/unsubscribe/t'], $headers)
		->willReturn(OperationResult::success());

	($this->run)([]);
});

it('gives a test copy a link that changes nothing, and no headers', function (): void {
	$this->unsubscribe->method('property')->willReturn('unsubscribed');
	$this->unsubscribe->method('previewUrl')->willReturn('https://example.com/admin/unsubscribe/preview');
	$this->unsubscribe->expects($this->never())->method('headers');
	$this->email->expects($this->once())->method('sendEmail')
		->with('news', $this->anything(), 'me@example.com', [], false, ['unsubscribeUrl' => 'https://example.com/admin/unsubscribe/preview'], [])
		->willReturn(OperationResult::success());

	($this->run)(['overrideTo' => 'me@example.com']);
});

it('sends an unsubscribed recipient a test copy, since it goes to the tester', function (): void {
	$this->unsubscribe->method('isUnsubscribed')->willReturn(true);
	$this->email->expects($this->once())->method('sendEmail')->willReturn(OperationResult::success());

	($this->run)(['overrideTo' => 'me@example.com']);
});

describe('a rendered send', function (): void {
	beforeEach(function (): void {
		$this->message = new RenderedBulkMessage('kit:campaign:c1', '{{ contact.email }}', '{{ contact.name }}', 'Hi', '<p>Hi {{ contact.name }}</p>', '', recipientFilter: 'Kit\\Filter', variables: 'Kit\\Vars');
		$this->repo->method('fetchMessage')->with('b1')->willReturnCallback(fn (): ?array => $this->message?->toArray());
		$this->rendered = fn (array $payload = []) => ($this->run)($payload + ['kind' => 'rendered', 'mailerId' => 'kit:campaign:c1']);
	});

	it('renders the stored message with the recipient as contact and the provider variables', function (): void {
		$this->hooks->method('accepts')->willReturn(true);
		$this->hooks->method('variables')->with($this->anything(), $this->stored)->willReturn(['vars' => ['unsubscribeUrl' => 'https://kit/u/1', 'business' => ['name' => 'Acme']], 'headers' => []]);
		$this->email->expects($this->never())->method('sendEmail');
		$this->email->expects($this->once())->method('sendRendered')
			->with(
				$this->callback(fn (RenderedBulkMessage $m): bool => $m == $this->message),
				$this->callback(fn (array $vars): bool => $vars['contact'] === $this->stored && $vars['unsubscribeUrl'] === 'https://kit/u/1' && $vars['business'] === ['name' => 'Acme']),
				null,
				[],
			)
			->willReturn(OperationResult::success());
		$this->repo->expects($this->once())->method('log')
			->with($this->callback(fn (array $row): bool => $row['status'] === 'sent' && $row['mailerId'] === 'kit:campaign:c1'));

		($this->rendered)();
	});

	it('dedupes on the source key', function (): void {
		$this->repo->expects($this->once())->method('hasBeenSent')->with('kit:campaign:c1', 'obj-1')->willReturn(true);
		$this->email->expects($this->never())->method('sendRendered');

		($this->rendered)();
	});

	it('skips a recipient the filter declines at send time', function (): void {
		$this->hooks->method('accepts')->willReturn(false);
		$this->email->expects($this->never())->method('sendRendered');
		$this->repo->expects($this->once())->method('log')
			->with($this->callback(fn (array $row): bool => $row['status'] === 'skipped' && $row['error'] !== null));

		($this->rendered)();
	});

	it('sends a test copy without asking the filter', function (): void {
		$this->hooks->expects($this->never())->method('accepts');
		$this->hooks->method('variables')->willReturn(['vars' => [], 'headers' => []]);
		$this->email->expects($this->once())->method('sendRendered')
			->with($this->anything(), $this->anything(), 'me@example.com', $this->anything())
			->willReturn(OperationResult::success());

		($this->rendered)(['overrideTo' => 'me@example.com']);
	});

	it('lets the caller headers win over Bulk Send headers, whatever their case', function (): void {
		$this->unsubscribe->method('headers')->willReturn(['List-Unsubscribe' => '<https://site/admin/unsubscribe/t>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
		$this->unsubscribe->method('url')->willReturn('https://site/admin/unsubscribe/t');
		$this->hooks->method('accepts')->willReturn(true);
		$this->hooks->method('variables')->willReturn(['vars' => [], 'headers' => ['list-unsubscribe' => '<https://kit/u/1>', 'X-Campaign' => 'c1']]);
		$this->email->expects($this->once())->method('sendRendered')
			->with($this->anything(), $this->anything(), null, [
				'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
				'list-unsubscribe'      => '<https://kit/u/1>',
				'X-Campaign'            => 'c1',
			])
			->willReturn(OperationResult::success());

		($this->rendered)();
	});

	it('logs a failure without retrying when the batch has no stored message', function (): void {
		// A retry cannot bring the message back.
		$this->message = null;
		$this->email->expects($this->never())->method('sendRendered');
		$this->repo->expects($this->once())->method('log')->with($this->callback(fn (array $row): bool => $row['status'] === 'failed'));

		($this->rendered)();
	});

	it('throws so the job is retried when the send fails', function (): void {
		$this->hooks->method('accepts')->willReturn(true);
		$this->hooks->method('variables')->willReturn(['vars' => [], 'headers' => []]);
		$this->email->method('sendRendered')->willReturn(OperationResult::failure('SMTP down'));

		expect(fn () => ($this->rendered)())->toThrow(RuntimeException::class);
	});
});
