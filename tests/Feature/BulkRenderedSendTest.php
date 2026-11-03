<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;
use TotalCMS\Domain\Mailer\Job\BulkEmailJob;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Domain\Mailer\Service\BulkMailerService;
use TotalCMS\Domain\Mailer\Service\BulkRecipientFilter;
use TotalCMS\Domain\Mailer\Service\BulkVariableProvider;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\OperationResult;

/**
 * A rendered bulk send end to end through the container, the way
 * BusinessKit uses it: queue the Twig source, run the jobs, cancel.
 */
final class FeatureSubscribedOnly implements BulkRecipientFilter
{
	public function accepts(array $object): bool
	{
		return ($object['status'] ?? '') === 'subscribed';
	}
}

final class FeatureCampaignVariables implements BulkVariableProvider
{
	public function variables(array $object): array
	{
		$url = 'https://kit.test/u/' . $object['id'];

		return ['vars' => ['unsubscribeUrl' => $url, 'business' => ['name' => 'Acme']], 'headers' => ['List-Unsubscribe' => '<' . $url . '>']];
	}
}

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c = $this->app->getContainer();
	$c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'contacts',
		'description' => 'Contacts',
		'properties'  => [
			'id'     => ['type' => 'string', 'field' => 'id', 'help' => 'Id'],
			'name'   => ['type' => 'string', 'field' => 'text', 'help' => 'Name'],
			'email'  => ['type' => 'string', 'field' => 'email', 'help' => 'Email'],
			'status' => ['type' => 'string', 'field' => 'text', 'help' => 'Email status'],
		],
		'required' => ['id'],
		'index'    => ['id', 'email', 'status'],
	]);
	$c->get(CollectionSaver::class)->saveCollection(['id' => 'contacts', 'name' => 'Contacts', 'schema' => 'contacts']);
	foreach (['ann' => 'Ann', 'bob' => 'Bob'] as $id => $name) {
		$c->get(ObjectSaver::class)->saveObject('contacts', ['id' => $id, 'name' => $name, 'email' => $id . '@example.com', 'status' => 'subscribed']);
	}

	$this->sent = [];
	$sender     = $this->createMock(EmailSender::class);
	$sender->method('send')->willReturnCallback(function (array $email): OperationResult {
		$this->sent[] = $email;

		return OperationResult::success('sent');
	});
	$c->set(EmailSender::class, $sender);

	$this->container = $c;
	$this->message   = new RenderedBulkMessage(
		sourceId: 'kit:campaign:spring',
		toTemplate: '{{ contact.email }}',
		toNameTemplate: '{{ contact.name }}',
		subjectTemplate: 'Spring news for {{ contact.name }}',
		bodyHtmlTemplate: '<p>{{ business.name }}: <a href="{{ unsubscribeUrl }}">Unsubscribe</a></p>',
		bodyTextTemplate: 'Unsubscribe: {{ unsubscribeUrl }}',
		recipientFilter: FeatureSubscribedOnly::class,
		variables: FeatureCampaignVariables::class,
	);
	$this->runJobs = function (): void {
		$job = $this->container->get(BulkEmailJob::class);
		foreach ($this->container->get(JobRepository::class)->fetchPendingJobs() as $pending) {
			$job->run($pending);
		}
	};
});

it('renders each recipient and skips one who unsubscribed after queueing', function (): void {
	$result = $this->container->get(BulkMailerService::class)->queueRenderedSend($this->message, 'contacts', ['ann', 'bob']);
	expect($result->success)->toBeTrue()->and($result->data['count'])->toBe(2);

	$this->container->get(ObjectPatcher::class)->patchObject('contacts', 'bob', ['status' => 'unsubscribed']);
	($this->runJobs)();

	expect($this->sent)->toHaveCount(1);
	$email = $this->sent[0];
	expect($email['to'])->toBe('ann@example.com')
		->and($email['toName'])->toBe('Ann')
		->and($email['subject'])->toBe('Spring news for Ann')
		->and($email['bodyHtml'])->toContain('Acme')->toContain('https://kit.test/u/ann')
		->and($email['bodyText'])->toBe('Unsubscribe: https://kit.test/u/ann')
		->and($email['headers'])->toBe(['List-Unsubscribe' => '<https://kit.test/u/ann>']);

	$log = $this->container->get(BulkMailerRepository::class)->fetchBatchLog($result->data['batchId']);
	expect(array_map(fn ($row) => [$row->objectId, $row->status], $log))->toEqualCanonicalizing([['ann', 'sent'], ['bob', 'skipped']]);
});

it('reaches each contact once per source', function (): void {
	$service = $this->container->get(BulkMailerService::class);
	$service->queueRenderedSend($this->message, 'contacts', ['ann']);
	($this->runJobs)();

	$again = $service->queueRenderedSend($this->message, 'contacts', ['ann', 'bob']);

	expect($again->data['count'])->toBe(1)->and($again->data['excluded'])->toBe(1);
});

it('cancels a scheduled send before anything goes out', function (): void {
	$service = $this->container->get(BulkMailerService::class);
	$queued  = $service->queueRenderedSend($this->message, 'contacts', ['ann', 'bob'], '2099-01-01 09:00');

	$cancelled = $service->cancelBatch($queued->data['batchId']);
	($this->runJobs)();

	expect($cancelled->data['cancelled'])->toBe(2)
		->and($this->container->get(JobRepository::class)->fetchPendingJobs())->toBe([])
		->and($this->sent)->toBe([])
		->and($this->container->get(BulkMailerRepository::class)->fetchMailerBatches('kit:campaign:spring')[0]->pending())->toBe(0);
});
