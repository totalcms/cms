<?php

declare(strict_types=1);
use Odan\Session\PhpSession;
use TotalCMS\Domain\Security\BotProtection;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\patchJson;
use function TotalCMS\Slim\Pest\postJson;

/**
 * Public self-registration opened from the collection's own settings, end to
 * end with auth ON. `members` is not in `auth.publicRegistration`: only its
 * `publicRegistration` toggle lets an anonymous visitor register, and its
 * `registrationGroups` are added to the global `publicRegistrationGroup`.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	signInAs($this->app, 'admin-user-test-com', 'auth');

	/** @var Config $config */
	$config                          = $this->app->getContainer()->get(Config::class);
	$auth                            = $config->auth;
	$auth['publicRegistration']      = [];
	$auth['publicRegistrationGroup'] = 'subscriber';
	$config->auth                    = $auth;

	postJson('/api/collections', [
		'id'                 => 'members',
		'schema'             => 'auth',
		'name'               => 'Members',
		'publicRegistration' => true,
		'registrationGroups' => ['member', 'vip'],
	])->assertOk();
});

/** Drop the session user: the next request is anonymous (auth stays on). */
function signOutRegistration(mixed $app): void
{
	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->delete(SessionKeys::AUTH_USER);
	$session->delete(SessionKeys::AUTH_COLLECTION);
}

/** @return list<array<string,mixed>> every stored user in `members` */
function storedMembers(): array
{
	$users = [];
	foreach (glob(cmsDataDir() . 'members/*.json') ?: [] as $file) {
		if (str_starts_with(basename($file), '.')) {
			continue;
		}
		$users[] = (array)json_decode((string)file_get_contents($file), true);
	}

	return $users;
}

it('registers an anonymous visitor into a collection opened by its toggle, with the union of groups', function (): void {
	signOutRegistration($this->app);

	postJson('/admin/register/members', [
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
		'groups'   => ['admin'], // forged: must be ignored
	])->assertOk();

	$users = storedMembers();
	expect($users)->toHaveCount(1);
	expect($users[0]['email'])->toBe('pat@example.test');
	expect($users[0]['groups'])->toBe(['subscriber', 'member', 'vip']);
	expect($users[0]['active'])->toBeTrue();
});

it('refuses registration once the toggle is turned off', function (): void {
	signOutRegistration($this->app);
	postJson('/admin/register/members', [
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertOk();

	signInAs($this->app, 'admin-user-test-com', 'auth');
	patchJson('/api/collections/members', ['publicRegistration' => false])->assertOk();
	signOutRegistration($this->app);

	postJson('/admin/register/members', [
		'name'     => 'Sam Member',
		'email'    => 'sam@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertStatus(403);

	expect(storedMembers())->toHaveCount(1);
});

it('removes a registration group that duplicates the global group', function (): void {
	patchJson('/api/collections/members', ['registrationGroups' => ['subscriber', 'member']])->assertOk();
	signOutRegistration($this->app);

	postJson('/admin/register/members', [
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertOk();

	expect(storedMembers()[0]['groups'])->toBe(['subscriber', 'member']);
});

it('shows the impersonate action on a toggle-enabled collection for a super admin', function (): void {
	postJson('/api/collections/members', [
		'id'       => 'pat',
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertOk();

	get('/admin/collections/members/pat')->assertOk()->assertSee('impersonate/members/pat');
});

it('does not show the impersonate action on a collection that is neither listed nor toggled', function (): void {
	postJson('/api/collections', ['id' => 'blog', 'schema' => 'blog', 'name' => 'Blog'])->assertOk();
	postJson('/api/collections/blog', ['id' => 'hello', 'title' => 'Hello'])->assertOk();

	get('/admin/collections/blog/hello')->assertOk()->assertDontSee('impersonate/');
});

it('ignores the toggle on a collection whose schema is not auth', function (): void {
	postJson('/api/collections', ['id' => 'blog', 'schema' => 'blog', 'name' => 'Blog', 'publicRegistration' => true])->assertOk();
	postJson('/api/collections/blog', ['id' => 'hello', 'title' => 'Hello'])->assertOk();

	get('/admin/collections/blog/hello')->assertOk()->assertDontSee('impersonate/');

	signOutRegistration($this->app);
	postJson('/admin/register/blog', [
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertStatus(403);
});

/*
 * The schema-editor group grants every collectionsMeta operation on every
 * collection, but it is not a super admin: it must not be able to open
 * registration or hand registrants a group (here `admin`) through it.
 */
it('refuses a collectionsMeta grantee changing the registration settings, while a super admin may', function (): void {
	signInAs($this->app, 'schema-editor-user-test-com', 'auth');

	patchJson('/api/collections/members', ['registrationGroups' => ['admin']])->assertStatus(403);
	patchJson('/api/collections/members', ['publicRegistration' => false])->assertStatus(403);
	patchJson('/api/collections/members', ['labelPlural' => 'People'])->assertOk();

	signInAs($this->app, 'admin-user-test-com', 'auth');
	patchJson('/api/collections/members', ['registrationGroups' => ['admin']])->assertOk();
});

/*
 * The serializer would store the string "false" as true. The saver and the
 * access middleware share one strict normaliser, so a grantee's "false" is
 * no change and stays off.
 */
it('keeps registration closed when a grantee sends a string false or a string group', function (): void {
	patchJson('/api/collections/members', ['publicRegistration' => false, 'registrationGroups' => []])->assertOk();

	signInAs($this->app, 'schema-editor-user-test-com', 'auth');
	patchJson('/api/collections/members', ['publicRegistration' => 'false'])->assertOk();
	patchJson('/api/collections/members', ['registrationGroups' => 'admin'])->assertOk();

	$stored = (array)json_decode((string)file_get_contents(cmsDataDir() . 'members/.meta.json'), true);
	expect($stored['publicRegistration'])->toBeFalse();
	expect($stored['registrationGroups'])->toBe([]);

	signOutRegistration($this->app);
	postJson('/admin/register/members', [
		'name'     => 'Pat Member',
		'email'    => 'pat@example.test',
		'password' => 'Correct-Horse-Battery-9',
	])->assertStatus(403);
});

it('answers a bot with a plain signup success and stores no user nor signs anyone in', function (): void {
	signOutRegistration($this->app);

	$response = postJson('/admin/register/members', [
		'name'                                                => 'Spam Bot',
		'email'                                               => 'bot@example.test',
		'password'                                            => 'Correct-Horse-Battery-9',
		BotProtection::HONEYPOT_FIELD                         => 'https://spam.example',
		BotProtection::TIMESTAMP_FIELD                        => (new BotProtection())->issue('members', 'register', time() - 10),
	]);

	$response->assertOk();
	$body = json_decode((string)$response->getBody(), true);
	// `members` does not verify email: a real signup gets no meta either.
	expect($body)->not->toHaveKey('meta');
	expect($body['data']['email'])->toBe('bot@example.test');
	expect($body['data']['active'])->toBeTrue();
	expect($body['data'])->not->toHaveKey('password');
	expect(storedMembers())->toBe([]);

	/** @var PhpSession $session */
	$session = $this->app->getContainer()->get(PhpSession::class);
	expect($session->get(SessionKeys::AUTH_USER))->toBeNull();
});

it('answers a bot with a check-your-email success when the collection verifies', function (): void {
	patchJson('/api/collections/members', ['requireEmailVerification' => true])->assertOk();
	signOutRegistration($this->app);

	$response = postJson('/admin/register/members', [
		'name'                         => 'Spam Bot',
		'email'                        => 'bot@example.test',
		'password'                     => 'Correct-Horse-Battery-9',
		BotProtection::HONEYPOT_FIELD  => 'https://spam.example',
		BotProtection::TIMESTAMP_FIELD => (new BotProtection())->issue('members', 'register', time() - 10),
	]);

	$response->assertOk();
	$body = json_decode((string)$response->getBody(), true);
	expect($body['meta']['requiresVerification'])->toBeTrue();
	expect($body['data']['active'])->toBeFalse();
	expect(storedMembers())->toBe([]);
});

it('tells a person whose form expired to reload, and stores no user', function (): void {
	signOutRegistration($this->app);

	$response = postJson('/admin/register/members', [
		'name'                         => 'Pat Member',
		'email'                        => 'pat@example.test',
		'password'                     => 'Correct-Horse-Battery-9',
		BotProtection::HONEYPOT_FIELD  => '',
		BotProtection::TIMESTAMP_FIELD => (new BotProtection())->issue('members', 'register', time() - BotProtection::MAX_AGE_SECONDS - 60),
	]);

	expect($response->getStatusCode())->toBe(422);
	expect(json_decode((string)$response->getBody(), true)['error']['message'])->toBe('This form has expired. Reload the page and try again.');
	expect(storedMembers())->toBe([]);
});

it('registers a person whose form carries a valid bot token', function (): void {
	signOutRegistration($this->app);

	postJson('/admin/register/members', [
		'name'                                                => 'Pat Member',
		'email'                                               => 'pat@example.test',
		'password'                                            => 'Correct-Horse-Battery-9',
		BotProtection::HONEYPOT_FIELD                         => '',
		BotProtection::TIMESTAMP_FIELD                        => (new BotProtection())->issue('members', 'register', time() - 10),
	])->assertOk();

	$users = storedMembers();
	expect($users)->toHaveCount(1);
	expect($users[0])->not->toHaveKey(BotProtection::HONEYPOT_FIELD);
	expect($users[0])->not->toHaveKey(BotProtection::TIMESTAMP_FIELD);
});
