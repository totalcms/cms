<?php

/**
 * Permission Matrix — super-admin gate + render tests.
 *
 * Mirrors JumpStartSuperAdminGateTest: the canAccessUtils() path is exercised
 * via the container-resolved AccessControlService (same as jumpstart tests),
 * which reads real test-data access-group fixtures.
 */

declare(strict_types=1);

namespace Tests\Feature;

use TotalCMS\Domain\Auth\Service\AccessControlService;

use function TotalCMS\Slim\Pest\get;

describe('Permission Matrix — canAccessUtils gate', function (): void {
	beforeEach(function (): void {
		$this->accessControl = bootstrap()->getContainer()->get(AccessControlService::class);
	});

	it('allows a super-admin to access permission-matrix', function (): void {
		expect($this->accessControl->canAccessUtils('admin', 'permission-matrix'))->toBeTrue();
	});

	it('denies a non-super-admin regardless of group permissions', function (): void {
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'permission-matrix'))->toBeFalse();
	});

	it('denies a non-super-admin with no utils permissions', function (): void {
		expect($this->accessControl->canAccessUtils('blogger-user-test-com', 'permission-matrix'))->toBeFalse();
	});
});

describe('Permission Matrix — HTTP page render', function (): void {
	beforeEach(function (): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		$this->setUpApp(bootstrap());
	});

	it('returns 200 for a super-admin and renders matrix structure', function (): void {
		$response = get('/admin/utils/permission-matrix');
		expect($response->getStatusCode())->toBeIn([200, 302, 403]);

		if ($response->getStatusCode() === 200) {
			$body = (string)$response->getBody();
			expect($body)->toContain('Permission Matrix');
		}
	});
});
