<?php

declare(strict_types=1);
use Odan\Session\PhpSession;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Session\SessionKeys;

use function TotalCMS\Slim\Pest\deleteJson;
use function TotalCMS\Slim\Pest\getJson;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\put;

/**
 * Owned collections and protected fields, end to end with auth ON.
 *
 * `portfolio` names `owner` as its ownerProperty. `draft` is forced to true
 * on every non-admin write (moderation), `featured` is admin-only. Two
 * members of the `editor` group — whose access group grants every operation
 * on every collection — each create a site. The group would let either edit
 * the other's; ownership must not.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	signInAs($this->app, 'admin-user-test-com', 'auth');

	postJson('/api/schemas', [
		'id'          => 'portfolio-schema',
		'description' => 'Member portfolio sites',
		'properties'  => [
			'id'       => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'label' => 'ID', 'help' => 'Slug', 'field' => 'id'],
			'title'    => ['type' => 'string', 'label' => 'Title', 'help' => 'Site name', 'field' => 'input'],
			'owner'    => ['type' => 'string', 'label' => 'Owner', 'help' => 'User id of the submitting member', 'field' => 'input'],
			'team'     => ['type' => 'array', 'items' => ['type' => 'string'], 'label' => 'Team', 'help' => 'Co-owners', 'field' => 'list'],
			'draft'    => ['type' => 'boolean', 'label' => 'Draft', 'help' => 'Awaiting review', 'field' => 'toggle', 'default' => true, 'protected' => ['value' => true]],
			'featured' => ['type' => 'boolean', 'label' => 'Featured', 'help' => 'Admin pick', 'field' => 'toggle', 'default' => false, 'protected' => true],
			'gallery'  => [
				'$ref'              => 'https://www.totalcms.co/schemas/properties/deck.json',
				'label'             => 'Gallery',
				'help'              => 'Screenshots of the site',
				'field'             => 'deck',
				'schemaref'         => 'https://www.totalcms.co/schemas/totalcms-item.json',
				'patternProperties' => ['^[a-z0-9][a-z0-9_]*$' => ['$ref' => 'https://www.totalcms.co/schemas/totalcms-item.json']],
			],
		],
		'required' => ['id', 'title'],
		'index'    => ['id', 'title', 'owner', 'team', 'draft', 'featured'],
	])->assertOk();

	postJson('/api/collections', [
		'id'            => 'portfolio',
		'schema'        => 'portfolio-schema',
		'name'          => 'Portfolio',
		'ownerProperty' => 'owner',
	])->assertOk();

	// A second member of the same group, created by the admin.
	postJson('/api/collections/auth', [
		'id'       => 'second-editor',
		'name'     => 'Second Editor',
		'email'    => 'second-editor@test.com',
		'password' => 'Correct-Horse-Battery-9',
		'groups'   => ['editor'],
		'active'   => true,
	])->assertOk();
});

/** @return array<string,mixed> */
function storedPortfolio(string $id): array
{
	return (array)json_decode((string)file_get_contents(cmsDataDir() . "portfolio/{$id}.json"), true);
}

function alexCreatesASite(mixed $app): void
{
	signInAs($app, 'editor-user-test-com', '');
	postJson('/api/collections/portfolio', [
		'id'       => 'alex-site',
		'title'    => 'Alex Site',
		'owner'    => 'second-editor', // forged: must be overwritten
		'draft'    => false,           // forged: must be forced back
		'featured' => true,            // forged: must be stripped
	])->assertOk();
}

function samCreatesASite(mixed $app): void
{
	signInAs($app, 'second-editor', '');
	postJson('/api/collections/portfolio', ['id' => 'sam-site', 'title' => 'Sam Site'])->assertOk();
}

/** Drop the session user: the next request is anonymous (auth stays on). */
function signOutOwned(mixed $app): void
{
	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start(); // as signInAs() does: a write before start() is lost when the session loads
	}
	$session->delete(SessionKeys::AUTH_USER);
	$session->delete(SessionKeys::AUTH_COLLECTION);
}

describe('create', function (): void {
	it('stamps the owner and forces the protected fields', function (): void {
		alexCreatesASite($this->app);

		$stored = storedPortfolio('alex-site');
		expect($stored['owner'])->toBe('editor-user-test-com');
		expect($stored['draft'])->toBeTrue();
		expect($stored['featured'])->toBeFalse();
	});
});

describe('another members object', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);
		signInAs($this->app, 'editor-user-test-com', '');
	});

	it('cannot be read', function (): void {
		getJson('/api/collections/portfolio/sam-site')->assertStatus(403);
	});

	it('cannot be patched', function (): void {
		patchJson('/api/collections/portfolio/sam-site', ['title' => 'Hijacked'])->assertStatus(403);
		expect(storedPortfolio('sam-site')['title'])->toBe('Sam Site');
	});

	it('cannot be deleted', function (): void {
		deleteJson('/api/collections/portfolio/sam-site')->assertStatus(403);
		expect(file_exists(cmsDataDir() . 'portfolio/sam-site.json'))->toBeTrue();
	});

	it('cannot receive an upload', function (): void {
		post('/api/upload/portfolio/sam-site/title')->assertStatus(403);
	});

	it('the owner upload route on an own object is not refused by the owner check', function (): void {
		// Positive control for the 403 above: same route, own object. Without a
		// file the action fails on its own terms, but never with 403.
		expect(post('/api/upload/portfolio/alex-site/title')->getStatusCode())->not->toBe(403);
	});

	it('cannot be cloned, and bulk delete and reindex are refused outright', function (): void {
		postJson('/api/collections/portfolio/alex-site/clone', ['id' => 'alex-copy'])->assertStatus(403);
		postJson('/api/collections/portfolio/bulk/delete', ['ids' => ['alex-site']])->assertStatus(403);
		put('/api/collections/portfolio/index')->assertStatus(403);
	});

	it('is a plain 404 when the id does not exist', function (): void {
		getJson('/api/collections/portfolio/nobody-site')->assertStatus(404);
	});

	it('cannot create an object by uploading to an id that does not exist', function (): void {
		// The property savers create a missing object outside the field
		// policy: no owner stamp, no forced draft. Members create through
		// object-save instead.
		post('/api/upload/portfolio/squat-site/title')->assertStatus(403);
		expect(file_exists(cmsDataDir() . 'portfolio/squat-site.json'))->toBeFalse();
	});
});

describe('own object', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);
		signInAs($this->app, 'editor-user-test-com', '');
	});

	it('can be read and edited, but the protected fields hold', function (): void {
		getJson('/api/collections/portfolio/alex-site')->assertOk();

		// Admin approves it first.
		signInAs($this->app, 'admin-user-test-com', 'auth');
		patchJson('/api/collections/portfolio/alex-site', ['draft' => false, 'featured' => true])->assertOk();
		expect(storedPortfolio('alex-site')['draft'])->toBeFalse();

		// Member edits the title only: draft returns to true, featured stays.
		signInAs($this->app, 'editor-user-test-com', '');
		patchJson('/api/collections/portfolio/alex-site', ['title' => 'Alex Site v2', 'featured' => false, 'owner' => 'second-editor'])->assertOk();

		$stored = storedPortfolio('alex-site');
		expect($stored['title'])->toBe('Alex Site v2');
		expect($stored['draft'])->toBeTrue();
		expect($stored['featured'])->toBeTrue();
		expect($stored['owner'])->toBe('editor-user-test-com');
	});

	it('cannot flip a protected field through the property route', function (): void {
		put('/api/collections/portfolio/alex-site/draft', ['draft' => false])->assertStatus(403);
		put('/api/collections/portfolio/alex-site/owner', ['owner' => 'second-editor'])->assertStatus(403);
	});

	it('cannot reset a protected field by deleting the property', function (): void {
		// Deleting a property resets it to the schema default; for `draft` that
		// default is true, so the test first has the admin approve the object.
		signInAs($this->app, 'admin-user-test-com', 'auth');
		patchJson('/api/collections/portfolio/alex-site', ['draft' => false])->assertOk();

		signInAs($this->app, 'editor-user-test-com', '');
		deleteJson('/api/collections/portfolio/alex-site/draft')->assertStatus(403);
		deleteJson('/api/collections/portfolio/alex-site/featured')->assertStatus(403);

		expect(storedPortfolio('alex-site')['draft'])->toBeFalse();
	});
});

describe('list routes', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);
		signInAs($this->app, 'editor-user-test-com', '');
	});

	it('A\'s query returns only A\'s objects', function (): void {
		getJson('/api/collections/portfolio/query')
			->assertOk()
			->assertHeader('X-Total', '1')
			->assertJsonPath('data.0.id', 'alex-site');
	});

	it('A\'s query stays scoped when ids names B\'s object', function (): void {
		getJson('/api/collections/portfolio/query?ids=sam-site,alex-site')
			->assertOk()
			->assertHeader('X-Total', '1')
			->assertJsonPath('data.0.id', 'alex-site');
	});

	it('A\'s index returns only A\'s objects', function (): void {
		getJson('/api/collections/portfolio/index')
			->assertOk()
			->assertJsonCount(1, 'data.objects')
			->assertJsonPath('data.objects.0.id', 'alex-site');
	});

	it('the admin sees everything', function (): void {
		signInAs($this->app, 'admin-user-test-com', 'auth');

		getJson('/api/collections/portfolio/query')->assertOk()->assertHeader('X-Total', '2');
	});
});

describe('a list owner property', function (): void {
	it('lets every co-owner in the list read the object and refuses everyone else', function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);

		// A third member of the same group, in no object's team.
		signInAs($this->app, 'admin-user-test-com', 'auth');
		postJson('/api/collections/auth', [
			'id'       => 'third-editor',
			'name'     => 'Third Editor',
			'email'    => 'third-editor@test.com',
			'password' => 'Correct-Horse-Battery-9',
			'groups'   => ['editor'],
			'active'   => true,
		])->assertOk();

		patchJson('/api/collections/portfolio', ['ownerProperty' => 'team'])->assertOk();
		patchJson('/api/collections/portfolio/alex-site', ['team' => ['editor-user-test-com', 'second-editor']])->assertOk();
		expect(storedPortfolio('alex-site')['team'])->toBe(['editor-user-test-com', 'second-editor']);

		foreach (['editor-user-test-com', 'second-editor'] as $member) {
			signInAs($this->app, $member, '');
			getJson('/api/collections/portfolio/alex-site')->assertOk();
			getJson('/api/collections/portfolio/query')
				->assertOk()
				->assertHeader('X-Total', '1')
				->assertJsonPath('data.0.id', 'alex-site');
		}

		signInAs($this->app, 'third-editor', '');
		getJson('/api/collections/portfolio/alex-site')->assertStatus(403);
		getJson('/api/collections/portfolio/query')->assertOk()->assertHeader('X-Total', '0');
	});
});

describe('id-less routes outside the allow-list', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);
	});

	it('refuses a member the collection export', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');
		getJson('/api/export/collections/portfolio/json')->assertStatus(403);
	});

	it('refuses a member the collection report', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');
		getJson('/api/report/collections/portfolio/json')->assertStatus(403);
	});

	it('still lets the admin export', function (): void {
		signInAs($this->app, 'admin-user-test-com', 'auth');
		getJson('/api/export/collections/portfolio/json')->assertOk();
	});
});

describe('property route on an own object', function (): void {
	it('applies the forced value in the same save', function (): void {
		alexCreatesASite($this->app);

		signInAs($this->app, 'admin-user-test-com', 'auth');
		patchJson('/api/collections/portfolio/alex-site', ['draft' => false])->assertOk();
		expect(storedPortfolio('alex-site')['draft'])->toBeFalse();

		signInAs($this->app, 'editor-user-test-com', '');
		put('/api/collections/portfolio/alex-site/title', ['title' => 'Alex Retitled'])->assertOk();

		$stored = storedPortfolio('alex-site');
		expect($stored['title'])->toContain('Alex Retitled'); // the property route stores the raw body as the value
		expect($stored['draft'])->toBeTrue();
	});
});

describe('public create on an owned collection', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);
		samCreatesASite($this->app);

		signInAs($this->app, 'admin-user-test-com', 'auth');
		patchJson('/api/collections/portfolio', ['publicOperations' => ['create']])->assertOk();

		signOutOwned($this->app);
	});

	it('does not let an anonymous caller clone an object', function (): void {
		// Precondition: the caller really is anonymous (a read is not public).
		expect(getJson('/api/collections/portfolio/sam-site')->getStatusCode())->toBe(401);

		$status = postJson('/api/collections/portfolio/sam-site/clone', ['id' => 'stolen-copy'])->getStatusCode();

		expect($status)->toBeGreaterThanOrEqual(300);
		expect(file_exists(cmsDataDir() . 'portfolio/stolen-copy.json'))->toBeFalse();
	});

	it('still accepts an anonymous create, with an empty owner', function (): void {
		$status = postJson('/api/collections/portfolio', ['id' => 'anon-site', 'title' => 'Anonymous Site'])->getStatusCode();

		expect($status)->toBeIn([200, 201]);
		$stored = storedPortfolio('anon-site');
		expect($stored['owner'])->toBe('');
		expect($stored['draft'])->toBeTrue();
	});

	it('stamps a signed-in editor as owner when they create through the public path', function (): void {
		// A public create skips the access middleware, but a signed-in caller
		// is still the actor the field policy stamps as owner.
		signInAs($this->app, 'editor-user-test-com', '');

		$status = postJson('/api/collections/portfolio', ['id' => 'editor-public-site', 'title' => 'Editor Public Site', 'owner' => 'sam'])->getStatusCode();

		expect($status)->toBeIn([200, 201]);
		$stored = storedPortfolio('editor-public-site');
		expect($stored['owner'])->toBe('editor-user-test-com');
		expect($stored['draft'])->toBeTrue();
	});
});

/*
 * `ownerCollection` names the auth collection whose members own objects here.
 * `partners` is a second auth collection; its user carries the same `editor`
 * group, so only the ownerCollection check can refuse them.
 */
describe('ownerCollection', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);

		signInAs($this->app, 'admin-user-test-com', 'auth');
		postJson('/api/collections', ['id' => 'partners', 'schema' => 'auth', 'name' => 'Partners'])->assertOk();
		postJson('/api/collections/partners', [
			'id'       => 'partner-editor',
			'name'     => 'Partner Editor',
			'email'    => 'partner-editor@test.com',
			'password' => 'Correct-Horse-Battery-9',
			'groups'   => ['editor'],
			'active'   => true,
		])->assertOk();
	});

	it('keeps an editor of the default auth collection working when it names that collection', function (): void {
		patchJson('/api/collections/portfolio', ['ownerCollection' => 'auth'])->assertOk();

		signInAs($this->app, 'editor-user-test-com', '');
		getJson('/api/collections/portfolio/alex-site')->assertOk();
		getJson('/api/collections/portfolio/query')->assertOk()->assertHeader('X-Total', '1');
	});

	it('lets the partners member in before ownerCollection is set', function (): void {
		// Precondition: the group lets the partner list the collection.
		signInAs($this->app, 'partner-editor', 'partners');
		getJson('/api/collections/portfolio/query')->assertOk()->assertHeader('X-Total', '0');
	});

	it('refuses a member of another auth collection, while the editor keeps working', function (): void {
		patchJson('/api/collections/portfolio', ['ownerCollection' => 'auth'])->assertOk();

		signInAs($this->app, 'partner-editor', 'partners');
		getJson('/api/collections/portfolio/alex-site')->assertStatus(403);
		getJson('/api/collections/portfolio/query')->assertStatus(403);
		postJson('/api/collections/portfolio', ['id' => 'partner-site', 'title' => 'Partner Site'])->assertStatus(403);
		expect(file_exists(cmsDataDir() . 'portfolio/partner-site.json'))->toBeFalse();

		signInAs($this->app, 'editor-user-test-com', '');
		getJson('/api/collections/portfolio/alex-site')->assertOk();
		getJson('/api/collections/portfolio/query')->assertOk()->assertHeader('X-Total', '1');
	});

	it('stamps a public create by an outsider as anonymous', function (): void {
		patchJson('/api/collections/portfolio', ['ownerCollection' => 'auth', 'publicOperations' => ['create']])->assertOk();

		signInAs($this->app, 'partner-editor', 'partners');
		$status = postJson('/api/collections/portfolio', ['id' => 'partner-public', 'title' => 'Partner Public', 'owner' => 'partner-editor'])->getStatusCode();

		expect($status)->toBeIn([200, 201]);
		expect(storedPortfolio('partner-public')['owner'])->toBe('');
	});
});

/*
 * Protected fields without ownership. `submissions` uses the portfolio schema
 * (forced `draft`, admin-only `featured`) but names no ownerProperty; `notes`
 * has no protected rules at all. Clone and the collection imports copy or
 * upsert whole objects with no field policy, so on `submissions` they are
 * refused to everyone but the admin, and a clone may never retarget another
 * collection through its body.
 */
describe('protected fields on an unowned collection', function (): void {
	beforeEach(function (): void {
		postJson('/api/collections', ['id' => 'submissions', 'schema' => 'portfolio-schema', 'name' => 'Submissions'])->assertOk();
		postJson('/api/schemas', [
			'id'          => 'note-schema',
			'description' => 'Plain notes',
			'properties'  => [
				'id'    => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'label' => 'ID', 'help' => 'Slug', 'field' => 'id'],
				'title' => ['type' => 'string', 'label' => 'Title', 'help' => 'Note title', 'field' => 'input'],
			],
			'required' => ['id', 'title'],
			'index'    => ['id', 'title'],
		])->assertOk();
		postJson('/api/collections', ['id' => 'notes', 'schema' => 'note-schema', 'name' => 'Notes'])->assertOk();

		// The admin approves one submission and writes one note.
		postJson('/api/collections/submissions', ['id' => 'approved', 'title' => 'Approved', 'draft' => false])->assertOk();
		expect((array)json_decode((string)file_get_contents(cmsDataDir() . 'submissions/approved.json'), true))->toHaveKey('draft', false);
		postJson('/api/collections/notes', ['id' => 'note-1', 'title' => 'Note'])->assertOk();
	});

	it('does not let an anonymous caller clone under a public create', function (): void {
		patchJson('/api/collections/submissions', ['publicOperations' => ['create']])->assertOk();
		signOutOwned($this->app);

		$status = postJson('/api/collections/submissions/approved/clone', ['id' => 'stolen-copy'])->getStatusCode();

		expect($status)->not->toBeIn([200, 201]);
		expect(file_exists(cmsDataDir() . 'submissions/stolen-copy.json'))->toBeFalse();
	});

	it('still accepts an anonymous create under a public create, with draft forced', function (): void {
		patchJson('/api/collections/submissions', ['publicOperations' => ['create']])->assertOk();
		signOutOwned($this->app);

		$status = postJson('/api/collections/submissions', ['id' => 'anon-entry', 'title' => 'Anonymous', 'draft' => false])->getStatusCode();

		expect($status)->toBeIn([200, 201]);
		$stored = (array)json_decode((string)file_get_contents(cmsDataDir() . 'submissions/anon-entry.json'), true);
		expect($stored['draft'])->toBeTrue();
	});

	it('refuses a member clone whose body retargets a protected collection', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');

		postJson('/api/collections/notes/note-1/clone', ['id' => 'hijack', 'collection' => 'portfolio'])->assertStatus(403);
		expect(file_exists(cmsDataDir() . 'portfolio/hijack.json'))->toBeFalse();
	});

	it('still lets a member clone within an unprotected collection', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');

		$status = postJson('/api/collections/notes/note-1/clone', ['id' => 'note-2', 'collection' => 'notes'])->getStatusCode();

		expect($status)->toBeIn([200, 201]);
		expect(file_exists(cmsDataDir() . 'notes/note-2.json'))->toBeTrue();
	});

	it('does not let an anonymous clone under a public create retarget another collection', function (): void {
		patchJson('/api/collections/notes', ['publicOperations' => ['create']])->assertOk();
		signOutOwned($this->app);

		$status = postJson('/api/collections/notes/note-1/clone', ['id' => 'hijack', 'collection' => 'submissions'])->getStatusCode();

		expect($status)->not->toBeIn([200, 201]);
		expect(file_exists(cmsDataDir() . 'submissions/hijack.json'))->toBeFalse();
	});

	it('refuses a member import into a collection with protected fields', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');

		postJson('/api/import/collections/submissions/json', ['data' => [['id' => 'approved', 'title' => 'Rewritten', 'draft' => false]]])->assertStatus(403);
		expect((array)json_decode((string)file_get_contents(cmsDataDir() . 'submissions/approved.json'), true))->toHaveKey('title', 'Approved');
	});
});

/*
 * Every other write path (deck items, files, uploads, meta, property deletes)
 * reaches storage through ObjectUpdater::updateObject(), which applies the
 * forced values the access middleware remembered for a non-admin write.
 */
describe('other write paths on an own object', function (): void {
	beforeEach(function (): void {
		alexCreatesASite($this->app);

		// Admin approves it.
		signInAs($this->app, 'admin-user-test-com', 'auth');
		patchJson('/api/collections/portfolio/alex-site', ['draft' => false])->assertOk();
		expect(storedPortfolio('alex-site')['draft'])->toBeFalse();
	});

	// `title` is required, so deleting it fails validation; `gallery` is not.
	it('forces draft back on a member property delete', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');
		$status = deleteJson('/api/collections/portfolio/alex-site/gallery')->getStatusCode();

		expect($status)->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
		expect(storedPortfolio('alex-site')['draft'])->toBeTrue();
	});

	it('forces draft back on a member deck item create', function (): void {
		signInAs($this->app, 'editor-user-test-com', '');
		$status = postJson('/api/collections/portfolio/alex-site/gallery/deck', ['id' => 'home', 'title' => 'Home page'])->getStatusCode();

		expect($status)->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
		$stored = storedPortfolio('alex-site');
		expect($stored['gallery'])->toHaveKey('home');
		expect($stored['draft'])->toBeTrue();
	});

	it('leaves draft alone on the admins property delete', function (): void {
		$status = deleteJson('/api/collections/portfolio/alex-site/gallery')->getStatusCode();

		expect($status)->toBeGreaterThanOrEqual(200)->toBeLessThan(300);
		expect(storedPortfolio('alex-site')['draft'])->toBeFalse();
	});
});

test('the container wires the owner check into FormServices', function (): void {
	$services = $this->app->getContainer()->get(FormServices::class);

	expect($services->session)->not->toBeNull()
		->and($services->userValidation)->not->toBeNull()
		->and($services->ownerProperties)->not->toBeNull();
});

test('saving a collection whose ownerProperty is not in its schema is rejected', function (): void {
	$response = postJson('/api/collections', [
		'id'            => 'portfolio-typo',
		'schema'        => 'portfolio-schema',
		'name'          => 'Portfolio typo',
		'ownerProperty' => 'nope',
	]);

	expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
	expect((string)$response->getBody())->toContain('ownerProperty');
});
