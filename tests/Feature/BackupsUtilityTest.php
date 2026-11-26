<?php

declare(strict_types=1);

use Slim\App;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;
use TotalCMS\Domain\Backup\Service\ObjectHistory;
use TotalCMS\Domain\Cache\CacheManager;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

/*
 * Utilities → Backups, through the real routes: the page in each of its
 * views, the restore POST, and who may use either. The page shows old values
 * of every field and the POST writes records, so each access rule here has a
 * test that fails when the rule is taken away.
 *
 * Fixture users: `admin-user-test-com` (super admin), `editor-user-test-com`
 * (group `editor`: every operation on every collection), `blogger-user-test-com`
 * (group `blogger`: the `blog` collection only). No fixture group has a
 * utility grant.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	signInAs($this->app, 'admin-user-test-com', 'auth');

	postJson('/api/schemas', ['id' => 'articles', 'description' => 'Articles', 'properties' => [
		'id'     => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'label' => 'ID', 'help' => 'Slug', 'field' => 'id'],
		'title'  => ['type' => 'string', 'label' => 'Title', 'help' => 'Title', 'field' => 'input'],
		'price'  => ['type' => 'number', 'label' => 'Price', 'help' => 'Price', 'field' => 'number'],
		'owner'  => ['type' => 'string', 'label' => 'Owner', 'help' => 'Owner', 'field' => 'input'],
		'secret' => ['$ref' => 'https://www.totalcms.co/schemas/properties/password.json', 'label' => 'Password', 'help' => 'Password', 'field' => 'password'],
	], 'required' => ['id'], 'index' => ['id', 'title', 'owner']])->assertOk();
	postJson('/api/collections', ['id' => 'articles', 'schema' => 'articles', 'name' => 'Articles'])->assertOk();
	postJson('/api/collections', ['id' => 'owned', 'schema' => 'articles', 'name' => 'Owned', 'ownerProperty' => 'owner'])->assertOk();

	postJson('/api/collections/articles', ['id' => 'one', 'title' => 'Autumn Sale', 'price' => 49, 'secret' => 'First-Password-1'])->assertOk();
	putJson('/api/collections/articles/one', ['id' => 'one', 'title' => 'Winter Sale', 'price' => 49])->assertOk();
	putJson('/api/collections/articles/one', ['id' => 'one', 'title' => 'Winter Sale', 'price' => 39])->assertOk();

	$this->history = $this->app->getContainer()->get(ObjectHistory::class);

	/** The snapshot file of an object's entry, newest first. */
	$this->snapshot = fn (string $collection, string $id, int $entry = 0): string => $this->history->entries($collection, $id)[$entry]['file'];

	$this->stored = fn (string $collection, string $id): array => (array)json_decode((string)file_get_contents(cmsDataDir() . "{$collection}/{$id}.json"), true);

	$this->page = fn (string $query = ''): string => (string)get('/admin/utils/backups' . ($query === '' ? '' : '?' . $query))->assertOk()->getBody();
});

/** Sign in as a fixture user whose group is given exactly these utility pages. */
function backupsSignIn(App $app, string $user, string $group, array $utils): void
{
	$repo  = $app->getContainer()->get(AccessGroupRepository::class);
	$found = $repo->findById($group);
	expect($found)->not->toBeNull();

	$permissions          = $found->permissions;
	$permissions['utils'] = ['all' => false, 'allowed' => $utils];
	$repo->save(new AccessGroupData(['id' => $group, 'description' => $found->description, 'operations' => $found->operations, 'permissions' => $permissions]));

	signInAs($app, $user, 'auth');
}

describe('the page', function (): void {
	test('with nothing chosen, offers the collections', function (): void {
		$html = ($this->page)();

		expect($html)->toContain('<h1>Backups</h1>')
			->toContain('<option value="articles"')
			->toContain('Restore from Backup');
	});

	test('for an object, lists its history with what each change touched', function (): void {
		$html = ($this->page)('collection=articles&id=one');

		expect($html)->toContain('<h2>one</h2>')
			->toContain('utils/backups?collection=articles">Articles</a>')
			->toContain('Edit object')
			->toContain('What changed')
			->toContain('Price')
			->toContain('Title')
			->toContain('restore=' . urlencode(($this->snapshot)('articles', 'one')));
	});

	test('comparing a change shows before and after in a dialog, and offers that one field', function (): void {
		$file = ($this->snapshot)('articles', 'one', 1);
		$html = ($this->page)('collection=articles&id=one&snapshot=' . urlencode($file));

		// Rendered open, so it shows without scripts; the page's script makes it modal.
		expect($html)->toContain('<dialog id="backups-compare"')
			->toContain('data-close="utils/backups?collection=articles&id=one"')
			->toContain('Autumn Sale')
			->toContain('Winter Sale')
			->toContain('Restore brings this back')
			->toContain('Restore this field')
			->toContain('property=title')
			->toContain('Restore everything to before this change')
			// Every Compare link is a plain link to this same view.
			->toContain('snapshot=' . urlencode($file) . '" data-compare')
			->and(($this->page)('collection=articles&id=one'))->not->toContain('<dialog id="backups-compare"');
	});

	test('asking to restore shows what would change, with a form that posts it', function (): void {
		$file = ($this->snapshot)('articles', 'one', 1);
		$html = ($this->page)('collection=articles&id=one&restore=' . urlencode($file));

		expect($html)->toContain('id="restore"')
			->toContain('After the restore')
			->toContain('action="utils/backups/restore"')
			->toContain('name="csrf_token"')
			->toContain('value="' . $file . '"')
			->toContain('The current version is saved first');
	});

	test('a password is never on the page, in any view', function (): void {
		putJson('/api/collections/articles/one', ['id' => 'one', 'title' => 'Winter Sale', 'price' => 39, 'secret' => 'Second-Password-2'])->assertOk();
		$file = ($this->snapshot)('articles', 'one');

		foreach (['', '&snapshot=' . urlencode($file), '&restore=' . urlencode($file)] as $view) {
			$html = ($this->page)('collection=articles&id=one' . $view);

			expect($html)->not->toContain('$2y$')->not->toContain('Second-Password-2');
		}

		expect(($this->page)('collection=articles&id=one&snapshot=' . urlencode($file)))->toContain('The value is never shown')
			->and(($this->page)('collection=articles&id=one&restore=' . urlencode($file)))->toContain('The password stays as it is now');
	});

	test('what a record held is escaped: it is content, not markup', function (): void {
		// Saved straight to disk: whatever a record holds, however it got there.
		$path   = cmsDataDir() . 'articles/one.json';
		$record = ['title' => '<img src=x onerror=alert(1)><b>bold</b>'] + (array)json_decode((string)file_get_contents($path), true);
		file_put_contents($path, json_encode($record));
		$this->app->getContainer()->get(CacheManager::class)->clearCollectionObjects('articles');
		putJson('/api/collections/articles/one', ['id' => 'one', 'title' => 'Safe again', 'price' => 39])->assertOk();

		$html = ($this->page)('collection=articles&id=one&snapshot=' . urlencode(($this->snapshot)('articles', 'one')));

		// The object's own sanitizer has already dropped the handler. What is
		// left is still markup, and is shown as text.
		expect($html)->toContain('&lt;b&gt;bold&lt;/b&gt;')
			->not->toContain('<b>bold</b>')
			->not->toContain('<img src=x');
	});

	test('what the query string says is escaped too', function (): void {
		$html = (string)get('/admin/utils/backups?collection=articles&id=' . urlencode('"><script>alert(2)</script>'))->getBody();

		expect($html)->not->toContain('<script>alert(2)</script>');
	});

	test('a collection that does not exist is refused in the same words as one you may not edit', function (): void {
		expect(($this->page)('collection=no-such-collection&id=one'))->toContain('There is no history here that you can open');
	});

	test('the object form\'s menu links here with its collection and id', function (): void {
		expect((string)get('/admin/collections/articles/one')->assertOk()->getBody())->toContain('utils/backups?collection=articles&id=one');
	});
});

describe('restoring', function (): void {
	test('a whole snapshot puts the record back and returns to its history', function (): void {
		$response = post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => ($this->snapshot)('articles', 'one', 1)]);

		expect($response->getStatusCode())->toBe(302)
			->and($response->getHeaderLine('Location'))->toContain('/admin/utils/backups?collection=articles&id=one')
			->and(($this->stored)('articles', 'one'))->toMatchArray(['title' => 'Autumn Sale', 'price' => 49]);

		// The result is shown once, on the page it returns to.
		expect(($this->page)('collection=articles&id=one'))->toContain('The object was restored.')
			->and(($this->page)('collection=articles&id=one'))->not->toContain('The object was restored.');
	});

	test('one field puts only that field back', function (): void {
		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => ($this->snapshot)('articles', 'one', 1), 'property' => 'title']);

		expect(($this->stored)('articles', 'one'))->toMatchArray(['title' => 'Autumn Sale', 'price' => 39])
			->and(($this->page)('collection=articles&id=one'))->toContain('The field was restored.');
	});

	test('keeps the password the record has now', function (): void {
		putJson('/api/collections/articles/one', ['id' => 'one', 'title' => 'Winter Sale', 'price' => 39, 'secret' => 'Second-Password-2'])->assertOk();
		$current = ($this->stored)('articles', 'one')['secret'];

		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => ($this->snapshot)('articles', 'one', 2)]);

		$restored = ($this->stored)('articles', 'one');

		expect($restored['title'])->toBe('Autumn Sale')
			->and($restored['secret'])->toBe($current)
			->and(password_verify('Second-Password-2', $restored['secret']))->toBeTrue();
	});

	test('a deleted object is listed for its collection and comes back whole', function (): void {
		TotalCMS\Slim\Pest\deleteJson('/api/collections/articles/one')->assertOk();

		$list = ($this->page)('collection=articles');
		$file = ($this->snapshot)('articles', 'one');

		expect($list)->toContain('<h2>Deleted objects</h2>')
			->toContain('Winter Sale')
			->toContain('restore=' . urlencode($file))
			->and(($this->page)('collection=articles&id=one&restore=' . urlencode($file)))->toContain('Bring one back as it was when it was deleted?');

		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => $file]);

		expect(($this->stored)('articles', 'one'))->toMatchArray(['title' => 'Winter Sale', 'price' => 39])
			->and(($this->page)('collection=articles&id=one'))->toContain('The object is back.')
			->and(($this->page)('collection=articles'))->toContain('No deleted object of this collection has a snapshot');
	});

	test('one field of a deleted object is not restored', function (): void {
		TotalCMS\Slim\Pest\deleteJson('/api/collections/articles/one')->assertOk();

		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => ($this->snapshot)('articles', 'one'), 'property' => 'title']);

		expect(file_exists(cmsDataDir() . 'articles/one.json'))->toBeFalse()
			->and(($this->page)('collection=articles&id=one'))->toContain('A deleted object can only be restored whole.');
	});

	test('a snapshot that is not one of the object\'s restores nothing and says so', function (): void {
		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => '../../auth/admin-user-test-com.json'])->assertStatus(302);

		expect(($this->stored)('articles', 'one')['title'])->toBe('Winter Sale')
			->and(($this->page)('collection=articles&id=one'))->toContain('That snapshot no longer exists');
	});

	test('is refused without the CSRF token', function (): void {
		TotalCMS\Slim\Pest\withHeader('X-CSRF-Token', 'not-the-token');

		expect(post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => ($this->snapshot)('articles', 'one', 1)])->getStatusCode())->toBe(403)
			->and(($this->stored)('articles', 'one')['title'])->toBe('Winter Sale');
	});
});

describe('who may use it', function (): void {
	test('the utility is granted like any other: without it, neither the page nor the restore answers', function (): void {
		$file = ($this->snapshot)('articles', 'one', 1);
		backupsSignIn($this->app, 'editor-user-test-com', 'editor', ['activity']);

		get('/admin/utils/backups?collection=articles&id=one')->assertForbidden();

		expect(post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => $file])->getStatusCode())->toBe(403)
			->and(($this->stored)('articles', 'one')['title'])->toBe('Winter Sale')
			// And the object form does not offer a link that would be refused.
			->and((string)get('/admin/collections/articles/one')->getBody())->not->toContain('utils/backups?collection=articles');
	});

	test('with it, a member who may edit the collection sees and restores', function (): void {
		$file = ($this->snapshot)('articles', 'one', 1);
		backupsSignIn($this->app, 'editor-user-test-com', 'editor', ['backups']);

		expect(($this->page)('collection=articles&id=one'))->toContain('What changed');

		post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => $file])->assertStatus(302);

		expect(($this->stored)('articles', 'one')['title'])->toBe('Autumn Sale');
	});

	test('a member who may not edit the collection sees nothing of it and restores nothing', function (): void {
		$file = ($this->snapshot)('articles', 'one', 1);
		backupsSignIn($this->app, 'blogger-user-test-com', 'blogger', ['backups']);

		$html = ($this->page)('collection=articles&id=one&snapshot=' . urlencode($file));

		expect($html)->toContain('There is no history here that you can open')
			->not->toContain('Autumn Sale')
			->not->toContain('<option value="articles"')
			->and(($this->page)('collection=articles'))->toContain('There is no history here that you can open')
			->and(post('/admin/utils/backups/restore', ['collection' => 'articles', 'id' => 'one', 'snapshot' => $file])->getStatusCode())->toBe(403)
			->and(($this->stored)('articles', 'one')['title'])->toBe('Winter Sale');
	});

	describe('in a collection where members own their objects', function (): void {
		beforeEach(function (): void {
			postJson('/api/collections/owned', ['id' => 'mine', 'title' => 'Mine v1', 'owner' => 'editor-user-test-com'])->assertOk();
			putJson('/api/collections/owned/mine', ['id' => 'mine', 'title' => 'Mine v2', 'owner' => 'editor-user-test-com'])->assertOk();
			postJson('/api/collections/owned', ['id' => 'theirs', 'title' => 'Theirs v1', 'owner' => 'someone-else'])->assertOk();
			putJson('/api/collections/owned/theirs', ['id' => 'theirs', 'title' => 'Theirs v2', 'owner' => 'someone-else'])->assertOk();

			$this->mine   = ($this->snapshot)('owned', 'mine');
			$this->theirs = ($this->snapshot)('owned', 'theirs');
		});

		test('a member sees and restores the history of their own object', function (): void {
			backupsSignIn($this->app, 'editor-user-test-com', 'editor', ['backups']);

			expect(($this->page)('collection=owned&id=mine&snapshot=' . urlencode($this->mine)))->toContain('Mine v1');

			post('/admin/utils/backups/restore', ['collection' => 'owned', 'id' => 'mine', 'snapshot' => $this->mine])->assertStatus(302);

			expect(($this->stored)('owned', 'mine')['title'])->toBe('Mine v1');
		});

		test('but not another member\'s', function (): void {
			backupsSignIn($this->app, 'editor-user-test-com', 'editor', ['backups']);

			expect(($this->page)('collection=owned&id=theirs&snapshot=' . urlencode($this->theirs)))->toContain('There is no history here that you can open')->not->toContain('Theirs v1')
				->and(post('/admin/utils/backups/restore', ['collection' => 'owned', 'id' => 'theirs', 'snapshot' => $this->theirs])->getStatusCode())->toBe(403)
				->and(($this->stored)('owned', 'theirs')['title'])->toBe('Theirs v2');
		});

		test('deleted objects are for administrators: a member cannot list them or bring one back', function (): void {
			TotalCMS\Slim\Pest\deleteJson('/api/collections/owned/mine')->assertOk();
			$file = ($this->snapshot)('owned', 'mine');

			backupsSignIn($this->app, 'editor-user-test-com', 'editor', ['backups']);

			expect(($this->page)('collection=owned'))->toContain('listed for administrators')->not->toContain('Mine v2')
				->and(($this->page)('collection=owned&id=mine'))->toContain('There is no history here that you can open')
				->and(post('/admin/utils/backups/restore', ['collection' => 'owned', 'id' => 'mine', 'snapshot' => $file])->getStatusCode())->toBe(403)
				->and(file_exists(cmsDataDir() . 'owned/mine.json'))->toBeFalse();

			signInAs($this->app, 'admin-user-test-com', 'auth');

			expect(($this->page)('collection=owned'))->toContain('Mine v2');

			post('/admin/utils/backups/restore', ['collection' => 'owned', 'id' => 'mine', 'snapshot' => $file])->assertStatus(302);

			expect(($this->stored)('owned', 'mine')['title'])->toBe('Mine v2');
		});
	});
});
