<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Unit tests for AuthTwigAdapter — the `cms.auth.*` surface templates use to
 * decide whether a visitor sees gated content.
 *
 * Every helper here is a production authorization boundary: a template that
 * asks `cms.auth.userLoggedIn('members')` or `cms.auth.canAccessCollection()`
 * and gets the wrong answer either leaks paid/private content to the public or
 * hides a customer's own content from them. These tests pin the delegation
 * contract (right service, right arguments, right short-circuits) rather than
 * re-testing the services themselves.
 *
 * Impersonation helpers live in AuthTwigAdapterImpersonationTest.php and the
 * bare login()-URL shape is asserted in TotalCMSTwigAdapter{Basic,Static}Test —
 * this file deliberately covers what those do not.
 */
describe('AuthTwigAdapter', function (): void {
	beforeEach(function (): void {
		$this->config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->api      = '';
		$this->config->auth     = ['enable' => true];
		$this->session          = $this->createMock(SessionInterface::class);
		$this->accessManager    = $this->createMock(AccessManager::class);
		$this->fileAccess       = $this->createMock(FileAccessManager::class);
		$this->accessControl    = $this->createMock(AccessControlService::class);
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->translator       = $this->createMock(TranslationService::class);
		$this->editionFeatures  = $this->createMock(EditionFeatureService::class);
		$this->userValidation   = $this->createMock(UserValidationService::class);
		$this->impersonation    = $this->createMock(ImpersonationServiceInterface::class);

		$this->adapter = new AuthTwigAdapter(
			$this->config,
			$this->session,
			$this->accessManager,
			$this->fileAccess,
			$this->accessControl,
			$this->collectionLister,
			$this->translator,
			$this->editionFeatures,
			$this->userValidation,
			$this->impersonation,
		);
	});

	// -------------------------------------------------------------------------
	// userLoggedIn() — the single most-called gate in customer templates
	// -------------------------------------------------------------------------

	describe('userLoggedIn()', function (): void {
		test('reports a logged-in visitor', function (): void {
			$this->accessManager->method('userLoggedIn')->willReturn(true);

			expect($this->adapter->userLoggedIn())->toBeTrue();
		});

		test('reports a logged-out visitor', function (): void {
			// The false path is what keeps `{% if cms.auth.userLoggedIn() %}`
			// blocks from rendering for anonymous traffic. If this ever
			// returned true by default, every gated block on every site would
			// render publicly.
			$this->accessManager->method('userLoggedIn')->willReturn(false);

			expect($this->adapter->userLoggedIn())->toBeFalse();
		});

		test('forwards the collection so a members session cannot satisfy an admin gate', function (): void {
			// Sites with several auth collections (admins + members) rely on the
			// collection argument reaching AccessManager verbatim. Dropping it
			// would make any logged-in session satisfy every gate.
			$this->accessManager
				->expects($this->once())
				->method('userLoggedIn')
				->with('members')
				->willReturn(true);

			expect($this->adapter->userLoggedIn('members'))->toBeTrue();
		});

		test('defaults to the empty collection when none is given', function (): void {
			$this->accessManager
				->expects($this->once())
				->method('userLoggedIn')
				->with('')
				->willReturn(false);

			expect($this->adapter->userLoggedIn())->toBeFalse();
		});
	});

	// -------------------------------------------------------------------------
	// userData()
	// -------------------------------------------------------------------------

	describe('userData()', function (): void {
		test('returns the session user record untouched', function (): void {
			// Templates render `cms.auth.userData().name` etc. The adapter must
			// not reshape or filter the record — any silent transformation here
			// would show the wrong person's details on the page.
			$record = ['id' => 'joe', 'name' => 'Joe Workman', 'groups' => ['admin']];
			$this->accessManager->method('userData')->willReturn($record);

			expect($this->adapter->userData())->toBe($record);
		});

		test('returns an empty array for an anonymous visitor', function (): void {
			// Empty (not null) matters: templates do `cms.auth.userData() is empty`
			// and a null would fatal on property access in strict Twig setups.
			$this->accessManager->method('userData')->willReturn([]);

			expect($this->adapter->userData())->toBe([]);
		});
	});

	// -------------------------------------------------------------------------
	// userHasAccess() — group membership check used to gate page sections
	// -------------------------------------------------------------------------

	describe('userHasAccess()', function (): void {
		test('forwards an array of groups plus the collection', function (): void {
			$this->accessManager
				->expects($this->once())
				->method('userHasAccess')
				->with(['gold', 'silver'], 'members')
				->willReturn(true);

			expect($this->adapter->userHasAccess(['gold', 'silver'], 'members'))->toBeTrue();
		});

		test('forwards a single group string unchanged', function (): void {
			// The union type means a template can pass 'gold' or ['gold'].
			// Coercing one into the other here would change the semantics of
			// AccessManager's match (any-of vs exact) and could over-grant.
			$this->accessManager
				->expects($this->once())
				->method('userHasAccess')
				->with('gold', '')
				->willReturn(false);

			expect($this->adapter->userHasAccess('gold'))->toBeFalse();
		});

		test('denies when the access manager denies', function (): void {
			$this->accessManager->method('userHasAccess')->willReturn(false);

			expect($this->adapter->userHasAccess(['gold']))->toBeFalse();
		});
	});

	// -------------------------------------------------------------------------
	// logout() — URL building
	// -------------------------------------------------------------------------

	describe('logout()', function (): void {
		test('builds the plain logout URL under the api base path', function (): void {
			$this->config->api = '/cms';

			expect($this->adapter->logout())->toBe('/cms/admin/logout');
		});

		test('omits the redirect parameter when the redirect is empty', function (): void {
			// A stray `?redirect=` would make the logout handler bounce to the
			// site root instead of honouring its own default.
			expect($this->adapter->logout(''))->toBe('/admin/logout');
		});

		test('url-encodes the redirect target', function (): void {
			// Un-encoded redirects truncate at the first `&`, silently dropping
			// query parameters the customer put in their logout link.
			expect($this->adapter->logout('/members/bye?ok=1&x=2'))
				->toBe('/admin/logout?redirect=%2Fmembers%2Fbye%3Fok%3D1%26x%3D2');
		});

		test('encodes an absolute redirect URL', function (): void {
			expect($this->adapter->logout('https://example.com/thanks'))
				->toBe('/admin/logout?redirect=https%3A%2F%2Fexample.com%2Fthanks');
		});

		test('logout() never reuses REQUEST_URI the way login() does', function (): void {
			// login() falls back to the current request; logout() deliberately
			// does not. Pin that difference so a future "consistency" refactor
			// cannot start bouncing users back to the gated page they just left.
			$previousUri            = $_SERVER['REQUEST_URI'] ?? null;
			$_SERVER['REQUEST_URI'] = '/members/secret';

			try {
				expect($this->adapter->logout())->toBe('/admin/logout');
			} finally {
				if ($previousUri !== null) {
					$_SERVER['REQUEST_URI'] = $previousUri;
				} else {
					unset($_SERVER['REQUEST_URI']);
				}
			}
		});
	});

	// -------------------------------------------------------------------------
	// login() — the REQUEST_URI fallback on the *real* adapter
	//
	// LoginFunctionTest exercises a hand-written copy of this method on an
	// anonymous class; these tests drive the shipped implementation.
	// -------------------------------------------------------------------------

	describe('login()', function (): void {
		// $_SERVER is process-global and Pest loads every test file into one
		// process, so pin it here and restore afterwards. Leaving it dirty
		// creates order-dependent failures under `pest --parallel` — a bug this
		// codebase has already been bitten by twice.
		beforeEach(function (): void {
			$this->authTwigPreviousUri = $_SERVER['REQUEST_URI'] ?? null;
			unset($_SERVER['REQUEST_URI']);
		});

		afterEach(function (): void {
			if ($this->authTwigPreviousUri !== null) {
				$_SERVER['REQUEST_URI'] = $this->authTwigPreviousUri;
			} else {
				unset($_SERVER['REQUEST_URI']);
			}
		});

		test('falls back to the current request so the visitor returns to the gated page', function (): void {
			// This is the whole point of the fallback: a visitor hitting a
			// protected page gets sent to login and back again. Losing it
			// dumps everyone on the dashboard after signing in.
			$_SERVER['REQUEST_URI'] = '/members/downloads';

			expect($this->adapter->login())->toBe('/admin/login?redirect=%2Fmembers%2Fdownloads');
		});

		test('an explicit redirect wins over the current request', function (): void {
			$_SERVER['REQUEST_URI'] = '/members/downloads';

			expect($this->adapter->login('', '/welcome'))->toBe('/admin/login?redirect=%2Fwelcome');
		});

		test('an explicit empty redirect suppresses the fallback entirely', function (): void {
			// `cms.auth.login('', '')` is the documented way to opt out of the
			// return-to-page behaviour; if the fallback still fired, a login
			// link in a shared header would leak the current URL into every page.
			$_SERVER['REQUEST_URI'] = '/members/downloads';

			expect($this->adapter->login('', ''))->toBe('/admin/login');
		});

		test('keeps the collection segment alongside the redirect', function (): void {
			$_SERVER['REQUEST_URI'] = '/members/downloads';

			expect($this->adapter->login('members'))
				->toBe('/admin/login/members?redirect=%2Fmembers%2Fdownloads');
		});

		test('honours the api base path', function (): void {
			$this->config->api = '/cms';

			expect($this->adapter->login('members', '/welcome'))
				->toBe('/cms/admin/login/members?redirect=%2Fwelcome');
		});
	});

	// -------------------------------------------------------------------------
	// sessionData()
	// -------------------------------------------------------------------------

	describe('sessionData()', function (): void {
		test('returns the stored value when the key exists', function (): void {
			$this->session->method('has')->with('flash')->willReturn(true);
			$this->session->method('get')->with('flash')->willReturn('saved');

			expect($this->adapter->sessionData('flash'))->toBe('saved');
		});

		test('returns null instead of reading a missing key', function (): void {
			// Reading an absent key straight off the session would raise in some
			// session drivers; the has() guard is what keeps templates from
			// blowing up on a first-visit page render.
			$this->session->method('has')->with('flash')->willReturn(false);
			$this->session->expects($this->never())->method('get');

			expect($this->adapter->sessionData('flash'))->toBeNull();
		});
	});

	// -------------------------------------------------------------------------
	// verifyFilePassword() — password gate in front of protected downloads
	// -------------------------------------------------------------------------

	describe('verifyFilePassword()', function (): void {
		test('loads a top-level file and verifies the password', function (): void {
			$this->fileAccess
				->expects($this->once())
				->method('loadFile')
				->with('docs', 'guide', 'pdf');
			$this->fileAccess->method('verfiyPasswordOnly')->with('hunter2')->willReturn(true);

			expect($this->adapter->verifyFilePassword('hunter2', 'docs', 'guide', 'pdf'))->toBeTrue();
		});

		test('splits a dotted property into root plus subpath for nested files', function (): void {
			// Card/deck children live inside a parent property. If the dot were
			// passed through as one property name the lookup misses, the wrong
			// file's password is compared, and the download either 404s or —
			// worse — matches a different file's hash.
			$this->fileAccess
				->expects($this->once())
				->method('loadFile')
				->with('docs', 'guide', 'mycard', 'file');
			$this->fileAccess->method('verfiyPasswordOnly')->willReturn(true);

			expect($this->adapter->verifyFilePassword('pw', 'docs', 'guide', 'mycard.file'))->toBeTrue();
		});

		test('walks a multi-level dotted property', function (): void {
			$this->fileAccess
				->expects($this->once())
				->method('loadFile')
				->with('docs', 'guide', 'deck', 'item/file');
			$this->fileAccess->method('verfiyPasswordOnly')->willReturn(false);

			expect($this->adapter->verifyFilePassword('pw', 'docs', 'guide', 'deck.item.file'))->toBeFalse();
		});

		test('uses the depot loader when a file name is supplied', function (): void {
			$this->fileAccess
				->expects($this->once())
				->method('loadDepotFile')
				->with('docs', 'guide', 'files');
			$this->fileAccess->expects($this->never())->method('loadFile');
			$this->fileAccess->method('verfiyPasswordOnly')->willReturn(true);

			expect($this->adapter->verifyFilePassword('pw', 'docs', 'guide', 'files', 'report.pdf'))->toBeTrue();
		});

		test('returns false on a wrong password', function (): void {
			// The deny path is the security-relevant one — it is what stops a
			// brute-forced form post from handing back a protected download.
			$this->fileAccess->method('verfiyPasswordOnly')->willReturn(false);

			expect($this->adapter->verifyFilePassword('wrong', 'docs', 'guide', 'pdf'))->toBeFalse();
		});
	});

	// -------------------------------------------------------------------------
	// isAdmin() — bypasses every other access control, so its guards matter most
	// -------------------------------------------------------------------------

	describe('isAdmin()', function (): void {
		test('grants everyone when auth is switched off site-wide', function (): void {
			// With auth disabled the admin UI has no users at all, so the
			// helpers must open up rather than lock the operator out of their
			// own site.
			$this->config->auth = ['enable' => false];
			$this->accessManager->expects($this->never())->method('userData');

			expect($this->adapter->isAdmin())->toBeTrue();
		});

		test('denies an anonymous visitor', function (): void {
			$this->accessManager->method('userData')->willReturn([]);
			$this->accessControl->expects($this->never())->method('isAdmin');

			expect($this->adapter->isAdmin())->toBeFalse();
		});

		test('denies a session record with no id', function (): void {
			// A partially-populated session (e.g. mid-login, or a legacy record)
			// must not be treated as an admin just because it is non-empty.
			$this->accessManager->method('userData')->willReturn(['name' => 'Joe']);
			$this->accessControl->expects($this->never())->method('isAdmin');

			expect($this->adapter->isAdmin())->toBeFalse();
		});

		test('delegates the id to AccessControlService and returns true', function (): void {
			$this->accessManager->method('userData')->willReturn(['id' => 'joe']);
			$this->accessControl
				->expects($this->once())
				->method('isAdmin')
				->with('joe')
				->willReturn(true);

			expect($this->adapter->isAdmin())->toBeTrue();
		});

		test('returns false when AccessControlService denies', function (): void {
			$this->accessManager->method('userData')->willReturn(['id' => 'member']);
			$this->accessControl->method('isAdmin')->willReturn(false);

			expect($this->adapter->isAdmin())->toBeFalse();
		});
	});

	// -------------------------------------------------------------------------
	// The can*() family — 13 near-identical gates. Each has the same three
	// behaviours (auth-off bypass, anonymous deny, delegate), so drive them all
	// from one dataset: a copy/paste slip in any one of them silently grants or
	// revokes access to a whole admin area.
	// -------------------------------------------------------------------------

	describe('can*() access gates', function (): void {
		// label => [adapter method, adapter args, AccessControlService method, expected args]
		$cases = [
			'canAccessCollection(update)'       => ['canAccessCollection', ['blog', 'update'], 'canAccessCollection', ['joe', 'blog', 'update']],
			'canAccessCollection(default read)' => ['canAccessCollection', ['blog'], 'canAccessCollection', ['joe', 'blog', 'read']],
			'canAccessCollectionsOperation'     => ['canAccessCollectionsOperation', ['delete'], 'canAccessCollectionsOperation', ['joe', 'delete']],
			'canAccessCollectionMeta'           => ['canAccessCollectionMeta', ['blog', 'update'], 'canAccessCollectionMeta', ['joe', 'blog', 'update']],
			'canAccessCollectionsMetaOperation' => ['canAccessCollectionsMetaOperation', ['create'], 'canAccessCollectionsMetaOperation', ['joe', 'create']],
			'canAccessSchema'                   => ['canAccessSchema', ['blog', 'update'], 'canAccessSchema', ['joe', 'blog', 'update']],
			'canAccessSchemasOperation'         => ['canAccessSchemasOperation', ['read'], 'canAccessSchemasOperation', ['joe', 'read']],
			// note the name shift: the adapter's canAccessUtil() maps to
			// AccessControlService::canAccessUtils(), and its canAccessUtils()
			// maps to canAccessAnyUtils(). Easy pair to wire backwards.
			'canAccessUtil'                     => ['canAccessUtil', ['sync'], 'canAccessUtils', ['joe', 'sync']],
			'canAccessUtils'                    => ['canAccessUtils', [], 'canAccessAnyUtils', ['joe']],
			'canAccessMailer'                   => ['canAccessMailer', [], 'canAccessMailer', ['joe']],
			'canAccessPlayground'               => ['canAccessPlayground', [], 'canAccessPlayground', ['joe']],
			'canAccessDataViews'                => ['canAccessDataViews', [], 'canAccessDataViews', ['joe']],
			'canAccessBuilder'                  => ['canAccessBuilder', [], 'canAccessBuilder', ['joe']],
			'canAccessExtension'                => ['canAccessExtension', ['acme/widgets'], 'canAccessExtension', ['joe', 'acme/widgets']],
			'canAccessDocs'                     => ['canAccessDocs', [], 'canAccessDocs', ['joe']],
		];

		test('delegates to AccessControlService with the resolved user id', function (
			string $method,
			array $args,
			string $control,
			array $controlArgs,
		): void {
			$this->accessManager->method('userData')->willReturn(['id' => 'joe']);
			$this->accessControl
				->expects($this->once())
				->method($control)
				->with(...$controlArgs)
				->willReturn(true);

			expect($this->adapter->{$method}(...$args))->toBeTrue();
		})->with($cases);

		test('denies an anonymous visitor without consulting AccessControlService', function (
			string $method,
			array $args,
			string $control,
		): void {
			// A logged-out visitor reaching an admin partial must be denied
			// *before* any access lookup — an empty user id has historically
			// been a soft spot in group-matching code.
			$this->accessManager->method('userData')->willReturn([]);
			$this->accessControl->expects($this->never())->method($control);

			expect($this->adapter->{$method}(...$args))->toBeFalse();
		})->with($cases);

		test('opens up when auth is disabled site-wide', function (
			string $method,
			array $args,
			string $control,
		): void {
			$this->config->auth = ['enable' => false];
			$this->accessManager->expects($this->never())->method('userData');
			$this->accessControl->expects($this->never())->method($control);

			expect($this->adapter->{$method}(...$args))->toBeTrue();
		})->with($cases);

		test('passes through a denial from AccessControlService', function (
			string $method,
			array $args,
			string $control,
		): void {
			// The false path is the one that hides admin nav items and blocks
			// gated partials; a helper that always returned true would expose
			// every admin area to every logged-in user.
			$this->accessManager->method('userData')->willReturn(['id' => 'member']);
			$this->accessControl->method($control)->willReturn(false);

			expect($this->adapter->{$method}(...$args))->toBeFalse();
		})->with($cases);
	});

	// -------------------------------------------------------------------------
	// accessibleCollections()
	// -------------------------------------------------------------------------

	describe('accessibleCollections()', function (): void {
		beforeEach(function (): void {
			$this->authTwigCollections = static function (string ...$ids): array {
				return array_map(static function (string $id): CollectionData {
					$collection     = new CollectionData();
					$collection->id = $id;

					return $collection;
				}, $ids);
			};
		});

		test('returns only the collections the user may read', function (): void {
			// This list drives the admin sidebar and collection pickers. Any
			// over-inclusion here shows a user collections they cannot open —
			// and, worse, leaks the existence and names of private collections.
			$this->collectionLister
				->method('listAllCollections')
				->willReturn(($this->authTwigCollections)('blog', 'private', 'gallery'));

			$this->accessManager->method('userData')->willReturn(['id' => 'joe']);
			$this->accessControl
				->method('canAccessCollection')
				->willReturnCallback(
					static fn (string $user, string $collection, string $op): bool => $collection !== 'private'
				);

			expect($this->adapter->accessibleCollections())->toBe(['blog', 'gallery']);
		});

		test('forwards a non-default operation to every check', function (): void {
			// `accessibleCollections('create')` powers "where can I add content?"
			// menus — read access is not enough to appear there.
			$this->collectionLister
				->method('listAllCollections')
				->willReturn(($this->authTwigCollections)('blog'));

			$this->accessManager->method('userData')->willReturn(['id' => 'joe']);
			$this->accessControl
				->expects($this->once())
				->method('canAccessCollection')
				->with('joe', 'blog', 'create')
				->willReturn(true);

			expect($this->adapter->accessibleCollections('create'))->toBe(['blog']);
		});

		test('returns an empty list for an anonymous visitor', function (): void {
			$this->collectionLister
				->method('listAllCollections')
				->willReturn(($this->authTwigCollections)('blog', 'gallery'));

			$this->accessManager->method('userData')->willReturn([]);

			expect($this->adapter->accessibleCollections())->toBe([]);
		});

		test('returns every collection when auth is disabled', function (): void {
			$this->config->auth = ['enable' => false];
			$this->collectionLister
				->method('listAllCollections')
				->willReturn(($this->authTwigCollections)('blog', 'gallery'));

			expect($this->adapter->accessibleCollections())->toBe(['blog', 'gallery']);
		});

		test('returns a list with sequential keys after filtering', function (): void {
			// The result is JSON-encoded in places; gaps in the keys would turn
			// the array into an object and break front-end consumers.
			$this->collectionLister
				->method('listAllCollections')
				->willReturn(($this->authTwigCollections)('a', 'b', 'c'));

			$this->accessManager->method('userData')->willReturn(['id' => 'joe']);
			$this->accessControl
				->method('canAccessCollection')
				->willReturnCallback(
					static fn (string $user, string $collection, string $op): bool => $collection === 'c'
				);

			expect(array_keys($this->adapter->accessibleCollections()))->toBe([0]);
		});
	});

	// -------------------------------------------------------------------------
	// passkeyManager() — must render nothing unless logged in AND licensed
	// -------------------------------------------------------------------------

	describe('passkeyManager()', function (): void {
		test('renders nothing for a logged-out visitor', function (): void {
			// The widget posts to authenticated passkey endpoints. Rendering it
			// publicly would put a registration button on a public page.
			$this->accessManager->method('userLoggedIn')->willReturn(false);
			$this->editionFeatures->expects($this->never())->method('can');

			expect($this->adapter->passkeyManager())->toBe('');
		});

		test('renders nothing when the edition does not include passkeys', function (): void {
			$this->accessManager->method('userLoggedIn')->willReturn(true);
			$this->editionFeatures
				->expects($this->once())
				->method('can')
				->with(EditionFeature::PASSKEYS)
				->willReturn(false);

			expect($this->adapter->passkeyManager())->toBe('');
		});

		test('renders nothing when passkeys are turned off in config', function (): void {
			$this->config->auth = ['enable' => true, 'usePasskeys' => false];
			$this->accessManager->method('userLoggedIn')->willReturn(true);
			$this->editionFeatures->method('can')->willReturn(true);

			expect($this->adapter->passkeyManager())->toBe('');
		});

		test('defaults to enabled when usePasskeys is absent from config', function (): void {
			// Older installs have no `usePasskeys` key; the `?? true` default is
			// what keeps passkeys working after an upgrade.
			$this->config->auth = ['enable' => true];
			$this->accessManager->method('userLoggedIn')->willReturn(true);
			$this->editionFeatures->method('can')->willReturn(true);
			$this->translator->method('trans')->willReturnArgument(0);

			expect($this->adapter->passkeyManager())->toContain('passkeys-manager');
		});

		test('renders the manager markup with the htmx list endpoint and api base', function (): void {
			// The list endpoint and data-api attribute are what the front-end
			// JS uses; a wrong base path silently breaks passkey management on
			// subfolder installs.
			$this->config->api  = '/cms';
			$this->config->auth = ['enable' => true, 'usePasskeys' => true];
			$this->accessManager->method('userLoggedIn')->willReturn(true);
			$this->editionFeatures->method('can')->willReturn(true);
			$this->translator->method('trans')->willReturnArgument(0);

			$html = $this->adapter->passkeyManager();

			expect($html)
				->toContain('id="passkeys-manager"')
				->toContain('hx-get="/cms/api/passkeys/list/html"')
				->toContain('data-api="/cms/api"')
				->toContain('id="passkey-register-btn"')
				->toContain('id="passkey-status"')
				->toContain('passkey.title')
				->toContain('passkey.register');
		});

		test('checks the session with no collection argument', function (): void {
			// passkeyManager() must ask about the *current* session, whatever
			// auth collection it belongs to. Hard-coding a collection here
			// would hide the widget from members-collection users entirely.
			$this->accessManager
				->expects($this->once())
				->method('userLoggedIn')
				->with('')
				->willReturn(false);

			expect($this->adapter->passkeyManager())->toBe('');
		});
	});
});
