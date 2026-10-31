<?php

declare(strict_types=1);

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Domain\Activity\Service\ActivityRecorder;
use TotalCMS\Domain\Activity\Service\MailActivity;
use TotalCMS\Domain\Activity\Service\McpToolActivity;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Payload\FileDownloadedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postUpload;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$container        = $this->app->getContainer();
	$this->repository = $container->get(ActivityRepository::class);
	$this->dispatcher = $container->get(EventDispatcher::class);
	$this->actors     = $container->get(ActorContext::class);
	$this->config     = $container->get(Config::class);
});

/**
 * @return list<ActivityEntry>
 */
function activityRows(ActivityFilter $filter = new ActivityFilter()): array
{
	return test()->repository->query($filter);
}

describe('recording from core events', function (): void {
	it('records an update with the names of the changed fields, not their values', function (): void {
		$container = $this->app->getContainer();
		$container->get(CollectionSaver::class)->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'blog']);
		$container->get(ObjectSaver::class)->saveObject('notes', ['id' => 'n1', 'title' => 'Before', 'summary' => 'Same']);

		$this->actors->set(new Actor(ActorKind::Member, 'member-1', 'members'));
		$container->get(ObjectUpdater::class)->updateObject('notes', 'n1', ['id' => 'n1', 'title' => 'After secret', 'summary' => 'Same']);

		$update = activityRows(new ActivityFilter(event: CoreEvent::OBJECT_UPDATED))[0] ?? null;

		expect($update)->not->toBeNull()
			->and($update->collection)->toBe('notes')
			->and($update->objectId)->toBe('n1')
			->and($update->userId)->toBe('member-1')
			->and($update->authCollection)->toBe('members')
			->and($update->actorKind)->toBe('member')
			->and($update->summary)->toContain('title')
			->and($update->summary)->not->toContain('summary')
			->and($update->summary)->not->toContain('After secret');
	});

	it('skips collection.updated when only the index or metadata moved', function (): void {
		$this->dispatcher->dispatch(CoreEvent::COLLECTION_UPDATED, new CollectionEventPayload('notes', configChanged: false));
		$this->dispatcher->dispatch(CoreEvent::COLLECTION_UPDATED, new CollectionEventPayload('notes', configChanged: true));

		expect(activityRows(new ActivityFilter(event: CoreEvent::COLLECTION_UPDATED)))->toHaveCount(1);
	});

	it('records a signed-in download and skips an anonymous one', function (): void {
		$payload = new FileDownloadedPayload('docs', 'd1', 'file', 'report.pdf', '', true);

		$this->actors->set(Actor::anonymous());
		$this->dispatcher->dispatch(CoreEvent::FILE_DOWNLOADED, $payload);

		$this->actors->set(new Actor(ActorKind::Member, 'member-1', 'members'));
		$this->dispatcher->dispatch(CoreEvent::FILE_DOWNLOADED, $payload);

		$rows = activityRows(new ActivityFilter(event: CoreEvent::FILE_DOWNLOADED));

		expect($rows)->toHaveCount(1)
			->and($rows[0]->userId)->toBe('member-1')
			->and($rows[0]->summary)->toBe('report.pdf')
			->and($rows[0]->detail)->toBe(['property' => 'file', 'protected' => true]);
	});

	it('records nothing when Settings → Activity turns recording off', function (): void {
		$this->config->activity = ['enable' => false];

		$this->dispatcher->dispatch(CoreEvent::COLLECTION_UPDATED, new CollectionEventPayload('notes', configChanged: true));

		expect($this->repository->count(new ActivityFilter()))->toBe(0);
	});
});

describe('the store', function (): void {
	it('filters by event, user, client, collection, level and date, newest first', function (): void {
		$recorder = $this->app->getContainer()->get(ActivityRecorder::class);
		$member   = ['kind' => 'member', 'userId' => 'ann', 'authCollection' => 'members', 'clientId' => 'claude'];

		$recorder->record('object.created', $member, ['collection' => 'blog', 'objectId' => 'a']);
		$recorder->record('access.denied', ['kind' => 'apikey', 'apiKeyId' => 'key-1'], ['collection' => 'blog', 'level' => ActivityEntry::LEVEL_WARNING]);
		$recorder->record('object.deleted', $member, ['collection' => 'news', 'objectId' => 'b']);

		$today = date('Y-m-d');

		expect(array_map(fn (ActivityEntry $e): string => $e->event, activityRows()))->toBe(['object.deleted', 'access.denied', 'object.created'])
			->and(activityRows(new ActivityFilter(user: 'ann')))->toHaveCount(2)
			->and(activityRows(new ActivityFilter(user: 'key-1')))->toHaveCount(1)
			->and(activityRows(new ActivityFilter(user: 'claude')))->toHaveCount(2)
			->and(activityRows(new ActivityFilter(collection: 'blog')))->toHaveCount(2)
			->and(activityRows(new ActivityFilter(level: 'warning')))->toHaveCount(1)
			->and(activityRows(new ActivityFilter(from: $today, to: $today)))->toHaveCount(3)
			->and(activityRows(new ActivityFilter(to: date('Y-m-d', strtotime('-1 day')))))->toHaveCount(0)
			->and($this->repository->distinct('collection'))->toBe(['blog', 'news']);
	});

	it('prunes rows past the retention, at most once a day', function (): void {
		$this->repository->record(new ActivityEntry('object.created', createdAt: time() - (100 * 86400)));
		$this->repository->record(new ActivityEntry('object.created', createdAt: time() - (10 * 86400)));

		expect($this->repository->pruneIfDue(90, time()))->toBe(1)
			->and($this->repository->pruneIfDue(1, time()))->toBe(0)
			->and($this->repository->count(new ActivityFilter()))->toBe(1);
	});

	it('reads an empty log without creating the database', function (): void {
		expect($this->repository->query(new ActivityFilter()))->toBe([])
			->and(file_exists($this->config->systemDir() . '/activity'))->toBeFalse();
	});
});

describe('MCP tool calls', function (): void {
	it('keeps the arguments that name a target and drops content', function (): void {
		$this->actors->set(new Actor(ActorKind::Member, 'ann', 'members', clientId: 'claude'));

		$this->app->getContainer()->get(McpToolActivity::class)->record('patch_object', [
			'collection' => 'blog',
			'id'         => 'post-1',
			'data'       => ['title' => 'Private draft'],
			'_session'   => 'x',
		], true, 12);

		$row = activityRows()[0];

		expect($row->event)->toBe(McpToolActivity::EVENT)
			->and($row->collection)->toBe('blog')
			->and($row->objectId)->toBe('post-1')
			->and($row->clientId)->toBe('claude')
			->and($row->summary)->toBe('patch_object')
			->and($row->detail)->toBe(['tool' => 'patch_object', 'latencyMs' => 12])
			->and(json_encode($row->detail))->not->toContain('Private draft');
	});

	it('skips anonymous callers unless the setting asks for them', function (): void {
		$activity = $this->app->getContainer()->get(McpToolActivity::class);
		$this->actors->set(Actor::anonymous());

		$activity->record('docs_search', ['query' => 'twig'], true, 3);
		expect($this->repository->count(new ActivityFilter()))->toBe(0);

		$this->config->activity = ['mcpAnonymous' => true];
		$activity->record('docs_search', ['query' => 'twig'], true, 3);
		expect(activityRows()[0]->detail['arguments'] ?? null)->toBe(['query' => 'twig']);
	});
});

describe('email', function (): void {
	it('records a send with its template, subject and recipient, never its body', function (): void {
		$this->actors->set(Actor::anonymous());

		$this->app->getContainer()->get(MailActivity::class)->sent([
			'to'       => 'jane@example.com',
			'subject'  => 'New message from the contact form',
			'bodyHtml' => '<p>My phone number is 555-0100</p>',
			'cc'       => ['office@example.com', ''],
			'bcc'      => [],
		], OperationResult::success('Email sent successfully'), 'contact-form');

		$row = activityRows()[0];

		expect($row->event)->toBe(MailActivity::SENT)
			->and($row->level)->toBe(ActivityEntry::LEVEL_INFO)
			->and($row->actorKind)->toBe('anonymous')
			->and($row->collection)->toBe('mailer')
			->and($row->objectId)->toBe('contact-form')
			->and($row->summary)->toBe('New message from the contact form')
			->and($row->detail)->toBe(['to' => 'jane@example.com', 'cc' => 1])
			->and(json_encode($row->detail))->not->toContain('555-0100');
	});

	it('marks a failed send as a warning with the reason', function (): void {
		$this->app->getContainer()->get(MailActivity::class)->sent(
			['to' => 'jane@example.com', 'subject' => 'Password Reset Request'],
			OperationResult::failure('SMTP connect() failed.', 'SMTP connect() failed.'),
		);

		$row = activityRows()[0];

		expect($row->level)->toBe(ActivityEntry::LEVEL_WARNING)
			->and($row->collection)->toBe('')
			->and($row->summary)->toBe('Password Reset Request: SMTP connect() failed.')
			->and($row->detail['error'] ?? null)->toBe('SMTP connect() failed.');
	});

	it('records a refusal before the email was built', function (): void {
		$this->app->getContainer()->get(MailActivity::class)->sent([], OperationResult::failure('Email template is not active'), 'newsletter');

		$row = activityRows()[0];

		expect($row->level)->toBe(ActivityEntry::LEVEL_WARNING)
			->and($row->objectId)->toBe('newsletter')
			->and($row->summary)->toBe('Email template is not active');
	});

	it('records a bulk send once, when it is queued', function (): void {
		$this->actors->set(new Actor(ActorKind::Admin, 'joe', 'auth'));

		$this->app->getContainer()->get(MailActivity::class)->bulkQueued('newsletter', 'members', 240, null);

		$row = activityRows()[0];

		expect($row->event)->toBe(MailActivity::BULK_QUEUED)
			->and($row->userId)->toBe('joe')
			->and($row->objectId)->toBe('newsletter')
			->and($row->summary)->toBe('240 emails from members')
			->and($row->detail)->toBe(['recipients' => 'members']);
	});
});

describe('downloads through the real route', function (): void {
	it('records a signed-in member downloading a file', function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'docs', 'name' => 'Docs', 'schema' => 'file']);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('docs', ['id' => 'd1']);

		$fixture = sys_get_temp_dir() . '/activity-doc-' . uniqid() . '.txt';
		file_put_contents($fixture, "hello\n");
		expect(postUpload('/api/collections/docs/d1/file', $fixture, 'text/plain', 'file')->getStatusCode())->toBe(200);

		signInAs($this->app, 'member-1', 'auth');
		expect(get('/download/docs/d1/file')->getStatusCode())->toBe(200);

		$rows = activityRows(new ActivityFilter(event: CoreEvent::FILE_DOWNLOADED));

		expect($rows)->toHaveCount(1)
			->and($rows[0]->userId)->toBe('member-1')
			->and($rows[0]->collection)->toBe('docs')
			->and($rows[0]->objectId)->toBe('d1')
			->and($rows[0]->summary)->toBe(basename($fixture));
	});
});

describe('the Activity page', function (): void {
	it('renders the filtered rows', function (): void {
		$recorder = $this->app->getContainer()->get(ActivityRecorder::class);
		$recorder->record('object.created', ['kind' => 'member', 'userId' => 'ann', 'authCollection' => 'members'], ['collection' => 'blog', 'objectId' => 'hello-world']);
		$recorder->record('object.created', ['kind' => 'member', 'userId' => 'bob', 'authCollection' => 'members'], ['collection' => 'news', 'objectId' => 'other-post']);

		$response = get('/admin/utils/activity?collection=blog');
		$body     = (string)$response->getBody();

		expect($response->getStatusCode())->toBe(200)
			->and($body)->toContain('Activity Log')
			->and($body)->toContain('hello-world')
			->and($body)->not->toContain('other-post');
	});

	it('shows the newest rows on the dashboard home, linking an object to its edit page', function (): void {
		$container = $this->app->getContainer();
		foreach (['qa-one', 'qa-two', 'qa-three', 'qa-four'] as $id) {
			$container->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => $id, 'schema' => 'blog']);
		}
		$container->get(ActivityRecorder::class)->record('object.updated', ['kind' => 'admin', 'userId' => 'joe', 'authCollection' => 'auth'], ['collection' => 'blog', 'objectId' => 'hello-world', 'summary' => 'title']);

		$body  = (string)get('/admin')->getBody();
		$start = (int)strpos($body, 'class="dashboard-section recent-activity"');

		expect($start)->toBeGreaterThan(0)
			->and(substr($body, $start, 3000))->toContain('href="collections/blog/hello-world"', 'auth/joe', 'href="utils/activity"');
	});

	it('puts a critical alert in a banner and the rest in the Needs Attention card, which is hidden when empty', function (): void {
		$container = $this->app->getContainer();
		foreach (['qa-one', 'qa-two', 'qa-three', 'qa-four'] as $id) {
			$container->get(CollectionSaver::class)->saveCollection(['id' => $id, 'name' => $id, 'schema' => 'blog']);
		}
		$config       = $container->get(Config::class);
		$config->smtp = ['from' => 'site@example.com'];

		expect((string)get('/admin')->getBody())->not->toContain('class="dashboard-section needs-attention"');

		$container->get(ActivityRecorder::class)->record(MailActivity::SENT, ['kind' => 'system'], ['level' => 'warning', 'summary' => 'Hello: refused']);
		$config->smtp = ['from' => ''];

		$body   = (string)get('/admin')->getBody();
		$banner = substr($body, (int)strpos($body, 'class="dashboard-alerts-panel"'), 1500);
		$card   = substr($body, (int)strpos($body, 'class="dashboard-section needs-attention"'), 1500);

		expect($banner)->toContain('settings/smtp')->not->toContain('could not be sent')
			->and($card)->toContain('1 email could not be sent since yesterday.')->not->toContain('settings/smtp');
	});

	it('renders an empty log', function (): void {
		$response = get('/admin/utils/activity');

		expect($response->getStatusCode())->toBe(200)
			->and((string)$response->getBody())->toContain('No activity');
	});
});

describe('the CSV export', function (): void {
	it('downloads every row the filters match, not just the page, with formulas escaped', function (): void {
		$recorder = $this->app->getContainer()->get(ActivityRecorder::class);
		$member   = ['kind' => 'member', 'userId' => 'ann', 'authCollection' => 'members', 'clientId' => 'claude'];
		for ($i = 0; $i < ActivityFilter::PAGE_SIZE + 5; $i++) {
			$recorder->record('object.created', $member, ['collection' => 'blog', 'objectId' => "post-{$i}"]);
		}
		$recorder->record('file.downloaded', $member, ['collection' => 'docs', 'objectId' => 'd1', 'summary' => '=HYPERLINK("http://evil")']);

		$response = get('/admin/utils/activity/export?collection=blog');
		$lines    = array_values(array_filter(explode("\n", (string)$response->getBody())));

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Type'))->toContain('text/csv')
			->and($response->getHeaderLine('Content-Disposition'))->toContain('attachment; filename="activity-')
			->and($lines[0])->toStartWith('Date,Event,Level,Actor,User')
			->and($lines)->toHaveCount(ActivityFilter::PAGE_SIZE + 5 + 1)
			->and((string)$response->getBody())->not->toContain('docs');

		$all = (string)get('/admin/utils/activity/export')->getBody();
		expect($all)->toContain("\"'=HYPERLINK")
			->and($all)->not->toContain(',=HYPERLINK');
	});

	it('names the file after the date range', function (): void {
		$response = get('/admin/utils/activity/export?from=2026-10-01&to=2026-10-22');

		expect($response->getStatusCode())->toBe(200)
			->and($response->getHeaderLine('Content-Disposition'))->toContain('activity-2026-10-01-to-2026-10-22.csv')
			->and(trim((string)$response->getBody()))->toStartWith('Date,Event');
	});

	it('shows an Export CSV link carrying the filters', function (): void {
		$this->app->getContainer()->get(ActivityRecorder::class)->record('object.created', ['kind' => 'admin', 'userId' => 'admin'], ['collection' => 'blog']);

		expect((string)get('/admin/utils/activity?collection=blog')->getBody())
			->toContain('href="utils/activity/export?collection=blog"');
	});
});
