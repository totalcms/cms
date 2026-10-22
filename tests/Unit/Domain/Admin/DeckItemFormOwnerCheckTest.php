<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Admin\Form\Builder\DeckItemForm;
use TotalCMS\Domain\Admin\Form\Builder\ObjectForm;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * cms.form.deck() / cms.form.deckBuilder() load a deck item from
 * `?id=&itemId=` and prefill it. On an owned collection DeckItemForm::init()
 * runs the same owner check as ObjectForm before the parent object is read,
 * so another member's item is never loaded and the form renders only the
 * refusal message.
 */
final class DeckItemFormOwnerCheckTest extends TestCase
{
	protected function tearDown(): void
	{
		unset($_GET['id'], $_GET['itemId']);
	}

	/**
	 * @param string $actor      session user id ('' for anonymous)
	 * @param bool   $superAdmin
	 * @param string $ownerProp  resolver result ('' = not owned)
	 * @param bool   $withChecks false builds the form without the owner-check services
	 */
	private function form(string $actor, bool $superAdmin, string $ownerProp, bool $withChecks = true): DeckItemForm
	{
		$config            = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->api       = '/api';
		$config->dashboard = [];

		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn([
			'id'      => 'doc-1',
			'owner'   => 'sam',
			'gallery' => ['item-1' => ['id' => 'item-1', 'title' => 'Sam secret']],
		]);

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
		$schema->properties = ['id' => ['field' => 'id'], 'title' => ['field' => 'text', 'label' => 'Title']];
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
			$resolver = $this->createMock(OwnerPropertyResolver::class);
			$resolver->method('resolve')->willReturn($ownerProp);

			$overrides += formOwnerServices($actor, $superAdmin, 'members', $resolver, $objectFetcher, $config, $this->createMock(LoggerFactory::class));
		}

		// The ids arrive from the URL, as on a front-end deck edit page.
		$_GET['id']     = 'doc-1';
		$_GET['itemId'] = 'item-1';

		return new DeckItemForm(formServices($overrides), new FormOptions(api: '/api', collection: 'docs', save: 'Save'), property: 'gallery');
	}

	private function read(DeckItemForm $form, string $name): mixed
	{
		for ($class = new \ReflectionClass($form); $class !== false; $class = $class->getParentClass()) {
			if ($class->hasProperty($name)) {
				return $class->getProperty($name)->getValue($form);
			}
		}

		return null;
	}

	private function assertLoaded(DeckItemForm $form): void
	{
		$this->assertSame('', $this->read($form, 'buildError'));
		$this->assertSame(['id' => 'item-1', 'title' => 'Sam secret'], $this->read($form, 'itemData'));
		$this->assertSame('PUT', $this->read($form, 'method'));
	}

	public function testANonOwnerGetsOnlyTheMessageAndNoItemData(): void
	{
		$form = $this->form('alex', false, 'owner');

		$this->assertSame(ObjectForm::OWNER_REFUSAL, $this->read($form, 'buildError'));
		$this->assertSame([], $this->read($form, 'itemData'));

		$html = $form->autoBuild();
		$this->assertStringContainsString(ObjectForm::OWNER_REFUSAL, $html);
		$this->assertStringNotContainsString('<input', $html);
		$this->assertStringNotContainsString('<button', $html);
		$this->assertStringNotContainsString('Sam secret', $html);
	}

	public function testAnAnonymousActorIsRefused(): void
	{
		$form = $this->form('', false, 'owner');

		$this->assertSame(ObjectForm::OWNER_REFUSAL, $this->read($form, 'buildError'));
		$this->assertSame([], $this->read($form, 'itemData'));
	}

	public function testTheOwnerLoadsTheItem(): void
	{
		$form = $this->form('sam', false, 'owner');

		$this->assertLoaded($form);
		$this->assertStringContainsString('<input', $form->autoBuild());
	}

	public function testASuperAdminLoadsTheItem(): void
	{
		$this->assertLoaded($this->form('alex', true, 'owner'));
	}

	public function testAnUnownedCollectionIsUnaffected(): void
	{
		$this->assertLoaded($this->form('alex', false, ''));
	}

	public function testWithoutTheServicesTheCheckIsSkipped(): void
	{
		$this->assertLoaded($this->form('alex', false, 'owner', false));
	}
}
