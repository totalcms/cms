<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\AccessControlService;

beforeEach(function (): void {
	// Use container to get properly configured service
	$container           = bootstrap()->getContainer();
	$this->accessControl = $container->get(AccessControlService::class);
});

describe('AccessControlService - Collections', function (): void {
	it('allows admin users full collection access', function (): void {
		// Admin user has admin group
		$result = $this->accessControl->canAccessCollection('admin', 'blog', 'GET');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('admin', 'blog', 'POST');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('admin', 'products', 'DELETE');
		expect($result)->toBeTrue();
	});

	it('allows blogger user to access blog collection', function (): void {
		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'GET');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'POST');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'PUT');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'DELETE');
		expect($result)->toBeTrue();
	});

	it('denies blogger user access to other collections', function (): void {
		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'products', 'GET');
		expect($result)->toBeFalse();

		$result = $this->accessControl->canAccessCollection('blogger-user-test-com', 'news', 'POST');
		expect($result)->toBeFalse();
	});

	it('allows viewer user read-only access to all collections', function (): void {
		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'GET');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'products', 'GET');
		expect($result)->toBeTrue();

		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'news', 'GET');
		expect($result)->toBeTrue();
	});

	it('denies viewer user write access to collections', function (): void {
		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'POST');
		expect($result)->toBeFalse();

		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'PUT');
		expect($result)->toBeFalse();

		$result = $this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'DELETE');
		expect($result)->toBeFalse();
	});

	it('checks general collections method permission', function (): void {
		// Admin can do anything
		expect($this->accessControl->canAccessCollectionsMethod('admin', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMethod('admin', 'POST'))->toBeTrue();

		// Viewer can only GET
		expect($this->accessControl->canAccessCollectionsMethod('viewer-user-test-com', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMethod('viewer-user-test-com', 'POST'))->toBeFalse();

		// Blogger has methods but restricted to specific collections
		expect($this->accessControl->canAccessCollectionsMethod('blogger-user-test-com', 'POST'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMethod('blogger-user-test-com', 'DELETE'))->toBeTrue();
	});
});

describe('AccessControlService - Schemas', function (): void {
	it('allows admin users full schema access', function (): void {
		expect($this->accessControl->canAccessSchema('admin', 'blog', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('admin', 'blog', 'POST'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('admin', 'products', 'DELETE'))->toBeTrue();
	});

	it('allows editor user to access specific schemas', function (): void {
		// Editor has GET access to blog and news schemas
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'blog', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'news', 'GET'))->toBeTrue();
	});

	it('denies editor user access to schemas not in allowed list', function (): void {
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'products', 'GET'))->toBeFalse();
	});

	it('denies blogger user write access to schemas', function (): void {
		// Blogger only has GET access to blog schema
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'POST'))->toBeFalse();
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'PUT'))->toBeFalse();
	});

	it('checks general schemas method permission', function (): void {
		expect($this->accessControl->canAccessSchemasMethod('admin', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasMethod('admin', 'POST'))->toBeTrue();

		expect($this->accessControl->canAccessSchemasMethod('viewer-user-test-com', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasMethod('viewer-user-test-com', 'POST'))->toBeFalse();

		expect($this->accessControl->canAccessSchemasMethod('blogger-user-test-com', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasMethod('blogger-user-test-com', 'POST'))->toBeFalse();
	});
});

describe('AccessControlService - Templates', function (): void {
	it('allows admin users full template access', function (): void {
		expect($this->accessControl->canAccessTemplatesMethod('admin', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessTemplatesMethod('admin', 'POST'))->toBeTrue();
		expect($this->accessControl->canAccessTemplatesMethod('admin', 'DELETE'))->toBeTrue();
	});

	it('allows editor user template access', function (): void {
		// Editor has templates: true
		expect($this->accessControl->canAccessTemplatesMethod('editor-user-test-com', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessTemplatesMethod('editor-user-test-com', 'POST'))->toBeTrue();
	});

	it('denies blogger user template access', function (): void {
		// Blogger has templates: false
		expect($this->accessControl->canAccessTemplatesMethod('blogger-user-test-com', 'GET'))->toBeFalse();
		expect($this->accessControl->canAccessTemplatesMethod('blogger-user-test-com', 'POST'))->toBeFalse();
	});

	it('denies viewer user template access', function (): void {
		expect($this->accessControl->canAccessTemplatesMethod('viewer-user-test-com', 'GET'))->toBeFalse();
	});
});

describe('AccessControlService - Settings', function (): void {
	it('allows admin users full settings access', function (): void {
		expect($this->accessControl->canAccessSettings('admin', 'general', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSettings('admin', 'cache', 'POST'))->toBeTrue();
		expect($this->accessControl->canAccessSettings('admin', 'mailer', 'PUT'))->toBeTrue();
	});

	it('allows editor user to access specific settings sections', function (): void {
		// Editor has access to "general" section
		expect($this->accessControl->canAccessSettings('editor-user-test-com', 'general', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSettings('editor-user-test-com', 'general', 'POST'))->toBeTrue();
	});

	it('denies editor user access to other settings sections', function (): void {
		expect($this->accessControl->canAccessSettings('editor-user-test-com', 'cache', 'GET'))->toBeFalse();
		expect($this->accessControl->canAccessSettings('editor-user-test-com', 'mailer', 'GET'))->toBeFalse();
	});

	it('denies blogger user settings access', function (): void {
		expect($this->accessControl->canAccessSettings('blogger-user-test-com', 'general', 'GET'))->toBeFalse();
	});

	it('checks general settings method permission', function (): void {
		expect($this->accessControl->canAccessSettingsMethod('admin', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessSettingsMethod('admin', 'POST'))->toBeTrue();

		// Editor has settings access but no methods array in permissions, so returns false
		expect($this->accessControl->canAccessSettingsMethod('editor-user-test-com', 'GET'))->toBeFalse();
		expect($this->accessControl->canAccessSettingsMethod('blogger-user-test-com', 'GET'))->toBeFalse();
	});
});

describe('AccessControlService - Utils', function (): void {
	it('allows admin users full utils access', function (): void {
		expect($this->accessControl->canAccessUtils('admin', 'cache-manager', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessUtils('admin', 'jumpstart', 'POST'))->toBeTrue();
		expect($this->accessControl->canAccessUtils('admin', 'server-checker', 'GET'))->toBeTrue();
	});

	it('allows editor user to access specific utils pages', function (): void {
		// Editor has access to "jumpstart" page
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'jumpstart', 'GET'))->toBeTrue();
	});

	it('denies editor user access to other utils pages', function (): void {
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'cache-manager', 'GET'))->toBeFalse();
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'api-keys', 'GET'))->toBeFalse();
	});

	it('denies blogger user utils access', function (): void {
		expect($this->accessControl->canAccessUtils('blogger-user-test-com', 'jumpstart', 'GET'))->toBeFalse();
	});

	it('checks general utils method permission', function (): void {
		expect($this->accessControl->canAccessUtilsMethod('admin', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessUtilsMethod('admin', 'POST'))->toBeTrue();

		// Editor has utils access but no methods array in permissions, so returns false
		expect($this->accessControl->canAccessUtilsMethod('editor-user-test-com', 'GET'))->toBeFalse();
		expect($this->accessControl->canAccessUtilsMethod('blogger-user-test-com', 'GET'))->toBeFalse();
	});
});

describe('AccessControlService - Boolean Permissions', function (): void {
	it('allows admin users access to all boolean permissions', function (): void {
		expect($this->accessControl->canAccessMailer('admin'))->toBeTrue();
		expect($this->accessControl->canAccessPlayground('admin'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('admin'))->toBeTrue();
	});

	it('allows editor user template and playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('editor-user-test-com'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('editor-user-test-com'))->toBeTrue();
	});

	it('denies editor user mailer access', function (): void {
		expect($this->accessControl->canAccessMailer('editor-user-test-com'))->toBeFalse();
	});

	it('allows blogger user playground and docs access', function (): void {
		expect($this->accessControl->canAccessPlayground('blogger-user-test-com'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('blogger-user-test-com'))->toBeTrue();
	});

	it('denies blogger user mailer access', function (): void {
		expect($this->accessControl->canAccessMailer('blogger-user-test-com'))->toBeFalse();
	});

	it('denies viewer user playground and mailer access', function (): void {
		expect($this->accessControl->canAccessPlayground('viewer-user-test-com'))->toBeFalse();
		expect($this->accessControl->canAccessMailer('viewer-user-test-com'))->toBeFalse();
	});

	it('allows viewer user docs access', function (): void {
		expect($this->accessControl->canAccessDocs('viewer-user-test-com'))->toBeTrue();
	});
});

describe('AccessControlService - Edge Cases', function (): void {
	it('throws exception for users that do not exist', function (): void {
		// Non-existent users should throw an exception (security measure)
		expect(fn () => $this->accessControl->canAccessCollection('invalid-user', 'blog', 'GET'))->toThrow(Exception::class, 'User invalid-user does not exist');
	});

	it('throws exception for completely non-existent users', function (): void {
		// Non-existent users should throw an exception (security measure)
		expect(fn () => $this->accessControl->canAccessCollection('non-existent-user', 'blog', 'GET'))->toThrow(Exception::class, 'User non-existent-user does not exist');
	});

	it('handles limited-blogger user correctly', function (): void {
		// Limited blogger has only GET access to blog
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'GET'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'POST'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'PUT'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'DELETE'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'news', 'GET'))->toBeFalse();
	});
});
