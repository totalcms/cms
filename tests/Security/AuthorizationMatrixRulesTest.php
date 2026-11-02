<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\OperationResult;

// Single rules the route matrix depends on: HEAD checks, key paths, public create, registration, the mailer and the job queue. See AuthorizationMatrix.php.
require_once __DIR__ . '/AuthorizationMatrix.php';

beforeEach(matrixBeforeEach());

describe('an anonymous id check (HEAD)', function (): void {
	// A public form's id field asks whether an id is taken. That works where
	// the public may create; everywhere else HEAD named which ids exist,
	// user ids in `auth` included.
	it('answers on a collection the public may create in', function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'matrix-public', 'name' => 'Public', 'schema' => 'blog', 'publicOperations' => ['create']]);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('matrix-public', ['id' => 'taken', 'title' => 'Taken']);

		expect(matrixRequest($this->app, 'HEAD', '/api/collections/matrix-public/taken')->getStatusCode())->toBe(200)
			->and(matrixRequest($this->app, 'HEAD', '/api/collections/matrix-public/free')->getStatusCode())->toBe(404);
	});

	it('refuses to say whether a user exists', function (): void {
		$taken = matrixRequest($this->app, 'HEAD', '/api/collections/auth/admin-user-test-com')->getStatusCode();
		$free  = matrixRequest($this->app, 'HEAD', '/api/collections/auth/nobody')->getStatusCode();

		expect($taken)->toBe($free)
			->and($taken)->not->toBe(200);
	});
});

describe('an API key held to its path', function (): void {
	// The key check matches the raw request path on segment boundaries, and
	// the router does not resolve dot segments, so none of these may reach
	// another collection with a key granted /collections/matrix-blog.
	it('cannot climb out with dot segments, encoded slashes or case', function (string $path): void {
		$response = matrixRequest($this->app, 'GET', $path, ['X-API-Key' => $this->readKey]);
		$status   = $response->getStatusCode();

		expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}")
			->and((string)$response->getBody())->not->toContain('admin-user@test.com');
	})->with([
		'dot segments'          => '/api/collections/' . MATRIX_COLLECTION . '/../auth/admin-user-test-com',
		'two levels up'         => '/api/collections/' . MATRIX_COLLECTION . '/../../collections/auth',
		'encoded dots'          => '/api/collections/' . MATRIX_COLLECTION . '/%2e%2e/auth/admin-user-test-com',
		'encoded slash'         => '/api/collections/' . MATRIX_COLLECTION . '%2F..%2Fauth',
		'encoded slash in id'   => '/api/collections/' . MATRIX_COLLECTION . '/..%2Fauth',
		'trailing dot'          => '/api/collections/' . MATRIX_COLLECTION . './../auth',
		'doubled slash'         => '/api//collections/auth',
		'other case, then up'   => '/api/collections/' . strtoupper(MATRIX_COLLECTION) . '/../auth',
		'dot segment in prefix' => '/api/./collections/auth',
	]);

	it('still reads what it was granted', function (): void {
		expect(matrixRequest($this->app, 'GET', '/api/collections/' . MATRIX_COLLECTION . '/' . MATRIX_OBJECT, ['X-API-Key' => $this->readKey])->getStatusCode())->toBe(200);
	});
});

describe('a collection open to public create only', function (): void {
	beforeEach(function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'matrix-inbox', 'name' => 'Inbox', 'schema' => 'blog', 'publicOperations' => ['create']]);
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('matrix-inbox', ['id' => 'someone-else', 'title' => 'Another visitor\'s message']);
		matrixWarmUp($this->app);
	});

	it('takes an anonymous submission', function (): void {
		expect(matrixRequest($this->app, 'POST', '/api/collections/matrix-inbox', [], ['id' => 'mine', 'title' => 'Hello'])->getStatusCode())->toBe(200)
			->and(is_file(objectPath('matrix-inbox', 'mine')))->toBeTrue();
	});

	it('never lets the public read, change, copy or delete what is there', function (string $method, string $path): void {
		$before = matrixFingerprint();
		$status = matrixRequest($this->app, $method, $path, [], ['id' => 'someone-else', 'title' => 'Overwritten'])->getStatusCode();

		expect($status < 200 || $status >= 300)->toBeTrue("{$method} {$path} answered {$status}")
			->and(matrixChanged($before, matrixFingerprint()))->toBe([]);
	})->with([
		'list'            => ['GET', '/api/collections/matrix-inbox'],
		'index'           => ['GET', '/api/collections/matrix-inbox/index'],
		'query'           => ['GET', '/api/collections/matrix-inbox/query'],
		'read'            => ['GET', '/api/collections/matrix-inbox/someone-else'],
		'replace'         => ['PUT', '/api/collections/matrix-inbox/someone-else'],
		'patch'           => ['PATCH', '/api/collections/matrix-inbox/someone-else'],
		'delete'          => ['DELETE', '/api/collections/matrix-inbox/someone-else'],
		'property'        => ['PUT', '/api/collections/matrix-inbox/someone-else/title'],
		'overwrite by id' => ['POST', '/api/collections/matrix-inbox'],
	]);
});

describe('public registration', function (): void {
	beforeEach(function (): void {
		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'signups', 'name' => 'Sign-ups', 'schema' => 'auth', 'publicRegistration' => true]);
		matrixWarmUp($this->app);
	});

	it('cannot grant itself groups, admin rights or a reset login count', function (): void {
		$response = matrixRequest($this->app, 'POST', '/admin/register/signups', [], [
			'id'            => 'newcomer',
			'name'          => 'Newcomer',
			'email'         => 'newcomer@example.test',
			'password'      => 'matrix-Password-1',
			'groups'        => ['admin'],
			'active'        => true,
			'maxLoginCount' => 0,
			'expiration'    => '',
		]);
		$stored = json_decode((string)file_get_contents(objectPath('signups', 'newcomer')), true);

		expect($response->getStatusCode())->toBe(200)
			->and($stored['groups'] ?? [])->not->toContain('admin');
	});

	it('leaves the newcomer with no admin and no API reads', function (): void {
		matrixRequest($this->app, 'POST', '/admin/register/signups', [], ['id' => 'newcomer', 'name' => 'Newcomer', 'email' => 'newcomer@example.test', 'password' => 'matrix-Password-1', 'groups' => ['admin']]);
		$headers = matrixSignIn($this->app, 'newcomer', 'signups');

		foreach (['/admin', '/admin/collections', '/api/collections/auth', '/api/collections/auth/admin-user-test-com', '/api/collections/' . MATRIX_COLLECTION, '/api/schemas'] as $path) {
			$status = matrixRequest($this->app, 'GET', $path, $headers)->getStatusCode();
			expect($status < 200 || $status >= 300)->toBeTrue("{$path} answered {$status}");
		}
	});

	it('still lets the newcomer edit their own profile', function (): void {
		matrixRequest($this->app, 'POST', '/admin/register/signups', [], ['id' => 'newcomer', 'name' => 'Newcomer', 'email' => 'newcomer@example.test', 'password' => 'matrix-Password-1']);
		$headers = matrixSignIn($this->app, 'newcomer', 'signups');

		expect(matrixRequest($this->app, 'PATCH', '/api/collections/signups/newcomer', $headers, ['name' => 'Renamed'])->getStatusCode())->toBe(200)
			->and(json_decode((string)file_get_contents(objectPath('signups', 'newcomer')), true)['name'])->toBe('Renamed');
	});

	it('cannot register into the admin\'s own auth collection', function (): void {
		$before = matrixFingerprint();
		$status = matrixRequest($this->app, 'POST', '/admin/register/auth', [], ['id' => 'intruder', 'name' => 'Intruder', 'email' => 'intruder@example.test', 'password' => 'matrix-Password-1'])->getStatusCode();

		expect($status)->toBe(403)
			->and(matrixChanged($before, matrixFingerprint()))->toBe([]);
	});
});

describe('the public mailer', function (): void {
	// Public by design: a form's mail action calls it from the browser. What
	// it must not do is let the caller choose who receives the mail when the
	// template fixes the recipients (notifications/mailer.md).
	beforeEach(function (): void {
		$this->sent = [];
		$sender     = $this->createMock(EmailSender::class);
		$sender->method('send')->willReturnCallback(function (array $email): OperationResult {
			$this->sent[] = $email;

			return OperationResult::success(EmailSender::SENT_MESSAGE);
		});
		$this->app->getContainer()->set(EmailSender::class, $sender);

		$this->app->getContainer()->get(CollectionSaver::class)->saveCollection(['id' => 'mailer', 'name' => 'Mailer', 'schema' => 'mailer']);
		$objects = $this->app->getContainer()->get(ObjectSaver::class);
		$objects->saveObject('mailer', ['id' => 'contact-notice', 'active' => true, 'name' => 'Contact notice', 'to' => 'owner@site.test', 'subject' => 'New message from {{ data.name }}', 'bodyHtml' => '<p>{{ data.message }}</p>']);
		matrixWarmUp($this->app);
	});

	it('sends a fixed template to its own recipient, whatever the data names', function (): void {
		$status = matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'contact-notice', 'data' => [
			'name'    => 'Visitor',
			'message' => 'Hi',
			'to'      => 'victim@elsewhere.test',
			'email'   => 'victim@elsewhere.test',
			'cc'      => 'victim@elsewhere.test',
			'bcc'     => 'victim@elsewhere.test',
			'replyTo' => 'victim@elsewhere.test',
		]])->getStatusCode();

		expect($status)->toBe(200)
			->and($this->sent)->toHaveCount(1)
			->and($this->sent[0]['to'])->toBe('owner@site.test')
			->and($this->sent[0]['cc'])->toBe([])
			->and($this->sent[0]['bcc'])->toBe([])
			->and(json_encode($this->sent[0]))->not->toContain('victim@elsewhere.test');
	});

	it('refuses a template that does not exist or is switched off', function (): void {
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('mailer', ['id' => 'retired', 'active' => false, 'name' => 'Retired', 'to' => 'owner@site.test', 'subject' => 'x', 'bodyHtml' => 'x']);

		matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'no-such-template', 'data' => []]);
		matrixRequest($this->app, 'POST', '/api/action/mailer', [], ['mailerId' => 'retired', 'data' => []]);

		expect($this->sent)->toBe([]);
	});
});

it('will not fan one data-driven recipient out to a list', function (): void {
	// A template whose To is {{ data.email }} sends to whatever the visitor
	// typed. PHPMailer must take that as one address or none, never as a list.
	$sender = $this->app->getContainer()->get(EmailSender::class);
	$result = $sender->send(['to' => 'a@one.test, b@two.test', 'subject' => 'x', 'bodyText' => 'x'], 1, recordActivity: false);

	expect($result->success)->toBeFalse();
});

describe('clearing the job queue', function (): void {
	beforeEach(function (): void {
		// A group whose only right is the Job Queue utility.
		$file               = cmsDataDir() . '.system/access-groups.json';
		$groups             = json_decode((string)file_get_contents($file), true);
		$groups['groups'][] = ['id' => 'queue-keeper', 'description' => 'Job queue only', 'permissions' => ['utils' => ['all' => false, 'allowed' => ['jobqueue']]]];
		file_put_contents($file, (string)json_encode($groups));
		$this->app->getContainer()->get(ObjectSaver::class)->saveObject('auth', ['id' => 'keeper', 'name' => 'Keeper', 'email' => 'keeper@example.test', 'password' => 'matrix-Password-1', 'groups' => ['queue-keeper'], 'active' => true]);
		// A new app, so the access groups are read again.
		$this->setUpApp(bootstrap());
		matrixEnableAuth($this->app);
	});

	it('is open to a user granted the Job Queue utility', function (string $path): void {
		$headers = matrixSignIn($this->app, 'keeper', 'auth');

		expect(matrixRequest($this->app, 'DELETE', $path, $headers)->getStatusCode())->toBe(200);
	})->with(['/api/jobqueue', '/api/jobqueue/status/failed', '/api/jobqueue/' . MATRIX_COLLECTION]);

	it('is refused to a read-only user', function (string $path): void {
		$headers = matrixSignIn($this->app, 'viewer-user-test-com', 'auth');

		expect(matrixRequest($this->app, 'DELETE', $path, $headers)->getStatusCode())->toBe(403);
	})->with(['/api/jobqueue', '/api/jobqueue/status/failed', '/api/jobqueue/' . MATRIX_COLLECTION]);
});
