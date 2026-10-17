<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Admin\Form\Builder\ObjectForm;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * The API refuses another member's object, but the form builder used to load
 * any object by `?id=` server-side and prefill it. ObjectForm::init() now
 * refuses before the object is fetched, so the data is never read.
 */
final class ObjectFormOwnerCheckTest extends TestCase
{
	private const MESSAGE = 'You do not have permission to edit this object.';

	protected function tearDown(): void
	{
		unset($_GET['id']);
	}

	/**
	 * @param string $actor      session user id ('' for anonymous)
	 * @param bool   $superAdmin
	 * @param string $ownerProp  resolver result ('' = not owned)
	 * @param bool   $withChecks false builds the form without the three owner services
	 * @param bool   $authOn     false switches authentication off for the install
	 */
	private function services(string $actor, bool $superAdmin, string $ownerProp, bool $withChecks = true, bool $authOn = true): FormServices
	{
		$config            = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->api       = '/api';
		$config->dashboard = [];
		if (!$authOn) {
			$config->auth = ['enable' => false];
		}

		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn(['id' => 'doc-1', 'owner' => 'sam', 'title' => 'Sam secret']);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturn(true);
		$objectFetcher->method('fetchObject')->willReturn($object);

		$collection         = new CollectionData();
		$collection->id     = 'docs';
		$collection->name   = 'Docs';
		$collection->schema = 'docs';

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('fetchCollection')->willReturn($collection);

		$schema             = new SchemaData();
		$schema->id         = 'docs';
		$schema->properties = ['title' => ['field' => 'text', 'label' => 'Title']];
		$schemaFetcher      = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchema')->willReturn($schema);

		$csrf = $this->createMock(CSRFTokenManager::class);
		$csrf->method('getTokenField')->willReturn('');

		$overrides = [
			'config'            => $config,
			'objectFetcher'     => $objectFetcher,
			'collectionFetcher' => $collectionFetcher,
			'schemaFetcher'     => $schemaFetcher,
			'csrfManager'       => $csrf,
			'metaResolver'      => $this->createMock(PropertyMetaResolver::class),
		];

		if ($withChecks) {
			$session = $this->createMock(SessionInterface::class);
			$session->method('get')->willReturnCallback(
				static fn (string $key): mixed => match ($key) {
					SessionKeys::AUTH_USER       => $actor === '' ? null : $actor,
					SessionKeys::AUTH_COLLECTION => $actor === '' ? null : 'members',
					default                      => null,
				}
			);
			$validation = $this->createMock(UserValidationService::class);
			$validation->method('isSuperAdmin')->willReturn($superAdmin);
			$resolver = $this->createMock(OwnerPropertyResolver::class);
			$resolver->method('resolve')->willReturn($ownerProp);

			$overrides['session']         = $session;
			$overrides['userValidation']  = $validation;
			$overrides['ownerProperties'] = $resolver;
		}

		return formServices($overrides);
	}

	/**
	 * @param string $actor      session user id ('' for anonymous)
	 * @param bool   $superAdmin
	 * @param string $ownerProp  resolver result ('' = not owned)
	 * @param bool   $withChecks false builds the form without the three owner services
	 * @param bool   $authOn     false switches authentication off for the install
	 */
	private function form(string $actor, bool $superAdmin, string $ownerProp, bool $withChecks = true, bool $authOn = true): ObjectForm
	{
		$reflection = new \ReflectionClass(ObjectForm::class);
		$form       = $reflection->newInstanceWithoutConstructor();
		$values     = [
			'register'   => false,
			'addOnly'    => false,
			'id'         => 'doc-1',
			'collection' => 'docs',
			'method'     => 'POST',
			'buildError' => '',
			'data'       => [],
			'services'   => $this->services($actor, $superAdmin, $ownerProp, $withChecks, $authOn),
		];
		foreach ($values as $name => $value) {
			for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
				if ($class->hasProperty($name)) {
					$class->getProperty($name)->setValue($form, $value);
					break;
				}
			}
		}

		(new \ReflectionMethod(ObjectForm::class, 'init'))->invoke($form);

		return $form;
	}

	private function read(ObjectForm $form, string $name): mixed
	{
		for ($class = new \ReflectionClass($form); $class !== false; $class = $class->getParentClass()) {
			if ($class->hasProperty($name)) {
				return $class->getProperty($name)->getValue($form);
			}
		}

		return null;
	}

	private function assertLoaded(ObjectForm $form): void
	{
		$this->assertSame('', $this->read($form, 'buildError'));
		$this->assertInstanceOf(ObjectData::class, $this->read($form, 'objectData'));
		$this->assertSame('PUT', $this->read($form, 'method'));
	}

	public function testANonOwnerGetsTheErrorStateAndNoObjectData(): void
	{
		$form = $this->form('alex', false, 'owner');

		$this->assertSame(self::MESSAGE, $this->read($form, 'buildError'));
		$this->assertNull($this->read($form, 'objectData'));
		$this->assertSame('POST', $this->read($form, 'method'));
	}

	public function testAnAnonymousActorIsRefused(): void
	{
		$form = $this->form('', false, 'owner');

		$this->assertSame(self::MESSAGE, $this->read($form, 'buildError'));
		$this->assertNull($this->read($form, 'objectData'));
	}

	public function testTheOwnerLoadsTheObject(): void
	{
		$this->assertLoaded($this->form('sam', false, 'owner'));
	}

	public function testASuperAdminLoadsTheObject(): void
	{
		$this->assertLoaded($this->form('alex', true, 'owner'));
	}

	public function testAnUnresolvedOwnerPropertyIsRefused(): void
	{
		// The collection record could not be read: no object carries the
		// sentinel property, so even the stored owner is refused.
		$form = $this->form('sam', false, OwnerPropertyResolver::UNRESOLVED);

		$this->assertSame(self::MESSAGE, $this->read($form, 'buildError'));
		$this->assertNull($this->read($form, 'objectData'));
	}

	public function testAnUnownedCollectionIsUnaffected(): void
	{
		$this->assertLoaded($this->form('alex', false, ''));
	}

	public function testWithoutTheServicesTheCheckIsSkipped(): void
	{
		$this->assertLoaded($this->form('alex', false, 'owner', false));
	}

	public function testWithAuthDisabledTheCheckIsSkipped(): void
	{
		// With auth off the actor is '' and every edit form on an owned
		// collection would otherwise show the error.
		$this->assertLoaded($this->form('', false, 'owner', true, false));
	}

	/** The whole form, built through the constructor and autoBuild() as cms.form.builder() does. */
	private function rendered(string $actor): string
	{
		$form = new ObjectForm(
			$this->services($actor, false, 'owner'),
			new FormOptions(api: '/api', collection: 'docs', id: 'doc-1', save: 'Save'),
		);

		return $form->autoBuild();
	}

	public function testARefusedFormRendersOnlyTheMessage(): void
	{
		$html = $this->rendered('alex');

		$this->assertStringContainsString(self::MESSAGE, $html);
		$this->assertStringNotContainsString('<input', $html);
		$this->assertStringNotContainsString('<button', $html);
		$this->assertStringNotContainsString('<form', $html);
		$this->assertStringNotContainsString('doc-1', $html);
	}

	public function testTheOwnersFormStillRendersFields(): void
	{
		$html = $this->rendered('sam');

		$this->assertStringNotContainsString(self::MESSAGE, $html);
		$this->assertStringContainsString('<form', $html);
		$this->assertStringContainsString('<input', $html);
		$this->assertStringContainsString('Save', $html);
	}
}
