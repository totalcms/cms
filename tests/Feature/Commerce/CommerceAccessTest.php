<?php

declare(strict_types=1);

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Repository\AccessGroupRepository;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupEditor;
use TotalCMS\Domain\Auth\Service\AccessControlService;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\putJson;

/*
 * Who may see a store's orders. Orders hold buyers' names, addresses and
 * what they paid, so the store's admin pages are their own grant: an access
 * group must say `commerce`, and no built-in group but admin does.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->boot = function (bool $open = true): void {
		bootCommerceStore($open);
		seedCommerceStaff($this->app);
		$this->c = $this->app->getContainer();
	};
});

describe('the store\'s admin pages', function (): void {
	it('lets in a super admin and a member whose group grants Commerce, and nobody else', function (string $user, int $status): void {
		($this->boot)();
		signInAs($this->app, $user, 'auth');

		expect(get('/admin/commerce')->getStatusCode())->toBe($status);
	})->with([
		'a super admin'             => ['boss', 200],
		'a member with the grant'   => ['keeper', 200],
		'a member without it'       => ['writer', 403],
	]);

	it('are not there on a site without Commerce, even for a super admin', function (): void {
		($this->boot)(false);
		signInAs($this->app, 'boss', 'auth');

		expect(get('/admin/commerce')->getStatusCode())->toBe(403);
	});
});

describe('the grant', function (): void {
	beforeEach(fn () => ($this->boot)());

	it('is held by the admin group and by no other built-in group', function (): void {
		$groups = $this->c->get(AccessGroupRepository::class);

		expect($groups->findById('admin')->permissions['commerce'])->toBeTrue();
		foreach (['editor', 'viewer', 'default'] as $id) {
			expect($groups->findById($id)->permissions['commerce'] ?? false)->toBeFalse();
		}
		expect((new AccessGroupData(['id' => 'new']))->permissions['commerce'])->toBeFalse();
	});

	it('is what a member\'s authority answers from', function (string $user, bool $can): void {
		expect($this->c->get(AccessControlService::class)->authorityOf($user, 'auth')->canCommerce())->toBe($can);
	})->with([['boss', true], ['keeper', true], ['writer', false]]);

	it('is not held by a group saved before it existed', function (): void {
		$groups = $this->c->get(AccessGroupRepository::class);
		$old    = $groups->findById('shopkeepers')->permissions;
		unset($old['commerce']);
		$groups->save(new AccessGroupData(['id' => 'shopkeepers', 'permissions' => $old]));

		expect($this->c->get(AccessControlService::class)->authorityOf('keeper', 'auth')->canCommerce())->toBeFalse();
	});

	it('can be set from the command line', function (): void {
		$group = $this->c->get(AccessGroupEditor::class)->patch('editor', ['permissions' => ['commerce' => true]]);

		expect($group->permissions['commerce'])->toBeTrue()
			->and(fn () => $this->c->get(AccessGroupEditor::class)->patch('editor', ['permissions' => ['commerce' => 'yes']]))->toThrow(DomainException::class);
	});
});

describe('the access group form', function (): void {
	it('offers the grant on a store, and saves it', function (): void {
		($this->boot)();
		signInAs($this->app, 'boss', 'auth');

		expect((string)get('/admin/utils/access-groups/new')->getBody())->toContain('value="commerce"');

		$on  = postJson('/api/access-groups', ['id' => 'packers', 'permissions-simple' => ['docs', 'commerce']]);
		$off = putJson('/api/access-groups/packers', ['id' => 'packers', 'permissions-simple' => ['docs']]);

		expect($on->getStatusCode())->toBe(200)
			->and(json_decode((string)$on->getBody(), true)['group']['permissions']['commerce'])->toBeTrue()
			->and(json_decode((string)$off->getBody(), true)['group']['permissions']['commerce'])->toBeFalse();
	});

	it('does not offer it on a site without Commerce, and leaves a grant made earlier alone', function (): void {
		($this->boot)(false);
		signInAs($this->app, 'boss', 'auth');

		expect((string)get('/admin/utils/access-groups/new')->getBody())->not->toContain('value="commerce"');

		// The store's add-on has lapsed: saving the group, whose form no
		// longer shows the grant, must not quietly take it away.
		$saved = putJson('/api/access-groups/shopkeepers', ['id' => 'shopkeepers', 'permissions-simple' => ['docs']]);
		$fresh = postJson('/api/access-groups', ['id' => 'packers', 'permissions-simple' => ['docs', 'commerce']]);

		expect(json_decode((string)$saved->getBody(), true)['group']['permissions']['commerce'])->toBeTrue()
			->and(json_decode((string)$fresh->getBody(), true)['group']['permissions']['commerce'])->toBeFalse();
	});
});
