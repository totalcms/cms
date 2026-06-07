<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\AccessControlService;

beforeEach(function (): void {
	// Use container to get properly configured service
	$container           = bootstrap()->getContainer();
	$this->accessControl = $container->get(AccessControlService::class);
});

describe('AccessControlService - Admin Access', function (): void {
	it('allows admin users full access to everything', function (): void {
		expect($this->accessControl->isAdmin('admin'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('admin', 'blog', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('admin', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('admin', 'blog', 'update'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('admin', 'blog', 'delete'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('admin', 'blog', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessUtils('admin', 'jumpstart'))->toBeTrue();
		expect($this->accessControl->canAccessMailer('admin'))->toBeTrue();
		expect($this->accessControl->canAccessPlayground('admin'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('admin'))->toBeTrue();
	});
});

describe('AccessControlService - Collections Access', function (): void {
	it('allows blogger full CRUD access to blog collection', function (): void {
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'update'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'blog', 'delete'))->toBeTrue();
	});

	it('denies blogger access to other collections', function (): void {
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'products', 'read'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('blogger-user-test-com', 'news', 'create'))->toBeFalse();
	});

	it('allows viewer read-only access to all collections', function (): void {
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'products', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'news', 'read'))->toBeTrue();
	});

	it('denies viewer write access to collections', function (): void {
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'create'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'update'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('viewer-user-test-com', 'blog', 'delete'))->toBeFalse();
	});

	it('allows editor full access to all collections', function (): void {
		expect($this->accessControl->canAccessCollection('editor-user-test-com', 'blog', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('editor-user-test-com', 'products', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('editor-user-test-com', 'news', 'update'))->toBeTrue();
	});

	it('allows limited-blogger read-only access to blog', function (): void {
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'create'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'update'))->toBeFalse();
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'blog', 'delete'))->toBeFalse();
	});

	it('denies limited-blogger access to other collections', function (): void {
		expect($this->accessControl->canAccessCollection('limited-user-test-com', 'news', 'read'))->toBeFalse();
	});
});

describe('AccessControlService - Collections Operation Access', function (): void {
	it('allows admin all collection operations', function (): void {
		expect($this->accessControl->canAccessCollectionsOperation('admin', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsOperation('admin', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsOperation('admin', 'update'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsOperation('admin', 'delete'))->toBeTrue();
	});

	it('allows viewer only read operation on collections', function (): void {
		expect($this->accessControl->canAccessCollectionsOperation('viewer-user-test-com', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsOperation('viewer-user-test-com', 'create'))->toBeFalse();
	});

	it('allows blogger all operations since they have collection access', function (): void {
		expect($this->accessControl->canAccessCollectionsOperation('blogger-user-test-com', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsOperation('blogger-user-test-com', 'delete'))->toBeTrue();
	});
});

describe('AccessControlService - Schemas Access', function (): void {
	it('allows editor read access to blog and news schemas', function (): void {
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'news', 'read'))->toBeTrue();
	});

	it('denies editor access to other schemas', function (): void {
		expect($this->accessControl->canAccessSchema('editor-user-test-com', 'products', 'read'))->toBeFalse();
	});

	it('allows blogger read access to blog schema', function (): void {
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'create'))->toBeFalse();
		expect($this->accessControl->canAccessSchema('blogger-user-test-com', 'blog', 'update'))->toBeFalse();
	});

	it('allows viewer read access to all schemas', function (): void {
		expect($this->accessControl->canAccessSchema('viewer-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessSchema('viewer-user-test-com', 'products', 'read'))->toBeTrue();
	});
});

describe('AccessControlService - Schemas Operation Access', function (): void {
	it('allows admin all schema operations', function (): void {
		expect($this->accessControl->canAccessSchemasOperation('admin', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasOperation('admin', 'read'))->toBeTrue();
	});

	it('allows viewer only read operation on schemas', function (): void {
		expect($this->accessControl->canAccessSchemasOperation('viewer-user-test-com', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasOperation('viewer-user-test-com', 'create'))->toBeFalse();
	});

	it('allows blogger read operation on schemas', function (): void {
		expect($this->accessControl->canAccessSchemasOperation('blogger-user-test-com', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessSchemasOperation('blogger-user-test-com', 'create'))->toBeFalse();
	});
});

describe('AccessControlService - Utils Access', function (): void {
	it('allows admin access to all utils', function (): void {
		expect($this->accessControl->canAccessUtils('admin', 'jumpstart'))->toBeTrue();
		expect($this->accessControl->canAccessUtils('admin', 'cache-manager'))->toBeTrue();
		expect($this->accessControl->canAccessAnyUtils('admin'))->toBeTrue();
	});

	it('allows editor access to specific utils', function (): void {
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'jumpstart'))->toBeTrue();
		expect($this->accessControl->canAccessUtils('editor-user-test-com', 'cache-manager'))->toBeFalse();
		expect($this->accessControl->canAccessAnyUtils('editor-user-test-com'))->toBeTrue();
	});

	it('denies blogger utils access', function (): void {
		expect($this->accessControl->canAccessUtils('blogger-user-test-com', 'jumpstart'))->toBeFalse();
		expect($this->accessControl->canAccessAnyUtils('blogger-user-test-com'))->toBeFalse();
	});

	it('denies viewer utils access', function (): void {
		expect($this->accessControl->canAccessUtils('viewer-user-test-com', 'jumpstart'))->toBeFalse();
		expect($this->accessControl->canAccessAnyUtils('viewer-user-test-com'))->toBeFalse();
	});
});

describe('AccessControlService - Mailer Access', function (): void {
	it('allows admin mailer access', function (): void {
		expect($this->accessControl->canAccessMailer('admin'))->toBeTrue();
	});

	it('denies non-admin mailer access', function (): void {
		expect($this->accessControl->canAccessMailer('editor-user-test-com'))->toBeFalse();
		expect($this->accessControl->canAccessMailer('blogger-user-test-com'))->toBeFalse();
		expect($this->accessControl->canAccessMailer('viewer-user-test-com'))->toBeFalse();
	});
});

describe('AccessControlService - Playground Access', function (): void {
	it('allows admin playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('admin'))->toBeTrue();
	});

	it('allows editor playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('editor-user-test-com'))->toBeTrue();
	});

	it('allows blogger playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('blogger-user-test-com'))->toBeTrue();
	});

	it('denies viewer playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('viewer-user-test-com'))->toBeFalse();
	});

	it('denies limited-blogger playground access', function (): void {
		expect($this->accessControl->canAccessPlayground('limited-user-test-com'))->toBeFalse();
	});
});

describe('AccessControlService - Builder Access', function (): void {
	it('allows admin builder access', function (): void {
		expect($this->accessControl->canAccessBuilder('admin'))->toBeTrue();
	});

	it('falls back to the templates permission for groups without a builder key', function (): void {
		// editor has templates=true and no builder key — legacy groups keep
		// the access that TemplateAccessMiddleware effectively granted them.
		expect($this->accessControl->canAccessBuilder('editor-user-test-com'))->toBeTrue();

		// viewer has templates=false and no builder key.
		expect($this->accessControl->canAccessBuilder('viewer-user-test-com'))->toBeFalse();
	});

	it('honors an explicit builder grant over the templates fallback', function (): void {
		// blogger has templates=false but builder=true.
		expect($this->accessControl->canAccessBuilder('blogger-user-test-com'))->toBeTrue();
	});

	it('denies limited-blogger builder access', function (): void {
		expect($this->accessControl->canAccessBuilder('limited-user-test-com'))->toBeFalse();
	});
});

describe('AccessControlService - Extensions Access', function (): void {
	it('allows admin access to any extension', function (): void {
		expect($this->accessControl->canAccessExtension('admin', 'test-vendor/hello-world'))->toBeTrue();
	});

	it('falls back to all-granted for groups without an extensions block', function (): void {
		// editor predates the extensions block — extension nav marked 'any'
		// was visible to every dashboard user, so upgrades preserve that.
		expect($this->accessControl->canAccessExtension('editor-user-test-com', 'test-vendor/hello-world'))->toBeTrue();
	});

	it('honors an explicit allowed list', function (): void {
		// blogger has extensions all=false, allowed=[test-vendor/hello-world].
		expect($this->accessControl->canAccessExtension('blogger-user-test-com', 'test-vendor/hello-world'))->toBeTrue();
		expect($this->accessControl->canAccessExtension('blogger-user-test-com', 'acme/other-ext'))->toBeFalse();
	});

	it('denies a group with all=false and an empty allowed list', function (): void {
		// viewer has extensions all=false, allowed=[].
		expect($this->accessControl->canAccessExtension('viewer-user-test-com', 'test-vendor/hello-world'))->toBeFalse();
	});
});

describe('AccessControlService - Docs Access', function (): void {
	it('allows all users docs access except limited-blogger', function (): void {
		expect($this->accessControl->canAccessDocs('admin'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('editor-user-test-com'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('blogger-user-test-com'))->toBeTrue();
		expect($this->accessControl->canAccessDocs('viewer-user-test-com'))->toBeTrue();
	});

	it('denies limited-blogger docs access', function (): void {
		expect($this->accessControl->canAccessDocs('limited-user-test-com'))->toBeFalse();
	});
});

describe('AccessControlService - Collection Metadata Access', function (): void {
	it('allows admin full collection metadata access', function (): void {
		expect($this->accessControl->canAccessCollectionMeta('admin', 'blog', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionMeta('admin', 'blog', 'read'))->toBeTrue();
	});

	it('allows editor read and update metadata for blog and image', function (): void {
		expect($this->accessControl->canAccessCollectionMeta('editor-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionMeta('editor-user-test-com', 'blog', 'update'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionMeta('editor-user-test-com', 'image', 'read'))->toBeTrue();
	});

	it('denies editor metadata access to other collections', function (): void {
		expect($this->accessControl->canAccessCollectionMeta('editor-user-test-com', 'products', 'read'))->toBeFalse();
	});

	it('allows blogger read-only metadata for blog', function (): void {
		expect($this->accessControl->canAccessCollectionMeta('blogger-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionMeta('blogger-user-test-com', 'blog', 'update'))->toBeFalse();
	});

	it('allows viewer read-only metadata for all collections', function (): void {
		expect($this->accessControl->canAccessCollectionMeta('viewer-user-test-com', 'blog', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionMeta('viewer-user-test-com', 'products', 'read'))->toBeTrue();
	});
});

describe('AccessControlService - Collection Metadata Operations', function (): void {
	it('allows admin all collection metadata operations', function (): void {
		expect($this->accessControl->canAccessCollectionsMetaOperation('admin', 'create'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMetaOperation('admin', 'delete'))->toBeTrue();
	});

	it('allows editor read and update operations on metadata', function (): void {
		expect($this->accessControl->canAccessCollectionsMetaOperation('editor-user-test-com', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMetaOperation('editor-user-test-com', 'update'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMetaOperation('editor-user-test-com', 'create'))->toBeFalse();
	});

	it('allows viewer only read operation on metadata', function (): void {
		expect($this->accessControl->canAccessCollectionsMetaOperation('viewer-user-test-com', 'read'))->toBeTrue();
		expect($this->accessControl->canAccessCollectionsMetaOperation('viewer-user-test-com', 'update'))->toBeFalse();
	});
});
