<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\ImpersonationServiceInterface;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Tests for the three impersonation helpers added to AuthTwigAdapter:
 *   cms.auth.isSuperAdmin()        — gate + target check
 *   cms.auth.isImpersonating()     — delegate to ImpersonationServiceInterface
 *   cms.auth.impersonatedUserId()  — target user id during impersonation
 */
describe('AuthTwigAdapter impersonation helpers', function (): void {
	beforeEach(function (): void {
		$this->config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->session         = $this->createMock(SessionInterface::class);
		$this->accessManager   = $this->createMock(AccessManager::class);
		$this->fileAccess      = $this->createMock(FileAccessManager::class);
		$this->accessControl   = $this->createMock(AccessControlService::class);
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->translator      = $this->createMock(TranslationService::class);
		$this->editionFeatures = $this->createMock(EditionFeatureService::class);
		$this->userValidation  = $this->createMock(UserValidationService::class);
		$this->impersonation   = $this->createMock(ImpersonationServiceInterface::class);

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
	// isSuperAdmin()
	// -------------------------------------------------------------------------

	describe('isSuperAdmin()', function (): void {
		test('returns true when current session user is super-admin', function (): void {
			$this->session
				->method('get')
				->with(SessionKeys::AUTH_USER)
				->willReturn('admin-joe');

			$this->userValidation
				->method('isSuperAdmin')
				->with('admin-joe')
				->willReturn(true);

			expect($this->adapter->isSuperAdmin())->toBeTrue();
		});

		test('returns false when current session user is not super-admin', function (): void {
			$this->session
				->method('get')
				->with(SessionKeys::AUTH_USER)
				->willReturn('regular-user');

			$this->userValidation
				->method('isSuperAdmin')
				->with('regular-user')
				->willReturn(false);

			expect($this->adapter->isSuperAdmin())->toBeFalse();
		});

		test('returns false when session has no user', function (): void {
			$this->session
				->method('get')
				->with(SessionKeys::AUTH_USER)
				->willReturn(null);

			// userValidation must NOT be called when there is no user id
			$this->userValidation
				->expects($this->never())
				->method('isSuperAdmin');

			expect($this->adapter->isSuperAdmin())->toBeFalse();
		});

		test('checks an explicit userId without touching the session', function (): void {
			$this->session
				->expects($this->never())
				->method('get');

			$this->userValidation
				->method('isSuperAdmin')
				->with('target-user')
				->willReturn(false);

			expect($this->adapter->isSuperAdmin('target-user'))->toBeFalse();
		});

		test('returns true for an explicit userId that is a super-admin', function (): void {
			$this->session
				->expects($this->never())
				->method('get');

			$this->userValidation
				->method('isSuperAdmin')
				->with('other-admin')
				->willReturn(true);

			expect($this->adapter->isSuperAdmin('other-admin'))->toBeTrue();
		});
	});

	// -------------------------------------------------------------------------
	// isImpersonating()
	// -------------------------------------------------------------------------

	describe('isImpersonating()', function (): void {
		test('delegates to ImpersonationServiceInterface and returns true', function (): void {
			$this->impersonation
				->method('isImpersonating')
				->willReturn(true);

			expect($this->adapter->isImpersonating())->toBeTrue();
		});

		test('delegates to ImpersonationServiceInterface and returns false', function (): void {
			$this->impersonation
				->method('isImpersonating')
				->willReturn(false);

			expect($this->adapter->isImpersonating())->toBeFalse();
		});
	});

	// -------------------------------------------------------------------------
	// impersonatedUserId()
	// -------------------------------------------------------------------------

	describe('impersonatedUserId()', function (): void {
		test('returns the current AUTH_USER when impersonating', function (): void {
			$this->impersonation
				->method('isImpersonating')
				->willReturn(true);

			$this->session
				->method('get')
				->with(SessionKeys::AUTH_USER)
				->willReturn('target-member');

			expect($this->adapter->impersonatedUserId())->toBe('target-member');
		});

		test('returns empty string when not impersonating', function (): void {
			$this->impersonation
				->method('isImpersonating')
				->willReturn(false);

			$this->session
				->expects($this->never())
				->method('get');

			expect($this->adapter->impersonatedUserId())->toBe('');
		});

		test('returns empty string when impersonating but session has no user', function (): void {
			$this->impersonation
				->method('isImpersonating')
				->willReturn(true);

			$this->session
				->method('get')
				->with(SessionKeys::AUTH_USER)
				->willReturn(null);

			expect($this->adapter->impersonatedUserId())->toBe('');
		});
	});
});
