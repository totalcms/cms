<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Admin;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Admin\ObjectForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

/**
 * `cms.form.builder('members', {register: true})` puts a form on a PUBLIC page
 * that writes into a collection of users. Everything else this class does is an
 * admin convenience; this mode is an internet-facing surface.
 *
 * The control worth holding is the add-only forcing. TotalForm::init() reads
 * `$_GET['id']` into the form's id and, for a normal admin form, loads that
 * object so it can be edited. On a public registration form that would mean
 * anyone appending `?id=admin` gets an existing user's record loaded into the
 * form. ObjectForm::init() sets addOnly BEFORE calling parent::init(), which is
 * what stops the read ever happening — an ordering detail with nothing in the
 * type system to protect it.
 */
final class ObjectFormRegisterModeTest extends TestCase
{
	protected function tearDown(): void
	{
		unset($_GET['id']);
	}

	/** @param array<string,mixed> $properties */
	private function form(array $properties): ObjectForm
	{
		$reflection = new \ReflectionClass(ObjectForm::class);
		$form       = $reflection->newInstanceWithoutConstructor();

		$config            = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->api       = '/api';
		$config->dashboard = [];

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('existsObject')->willReturn(true);
		$objectFetcher->method('fetchObject')
			->willThrowException(new \RuntimeException('an existing object must not be loaded here'));

		$collection             = new CollectionData();
		$collection->id         = 'members';
		$collection->name       = 'Members';
		$collection->schema     = 'members';

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('fetchCollection')->willReturn($collection);

		$schemaFetcher = $this->createMock(\TotalCMS\Domain\Schema\Service\SchemaFetcher::class);
		$schemaFetcher->method('fetchSchema')->willReturn(new \TotalCMS\Domain\Schema\Data\SchemaData());

		$defaults = [
			'register'          => false,
			'addOnly'           => false,
			'id'                => '',
			'collection'        => 'members',
			'method'            => 'POST',
			'data'              => [],
			'config'            => $config,
			'objectFetcher'     => $objectFetcher,
			'collectionFetcher' => $collectionFetcher,
			'schemaFetcher'     => $schemaFetcher,
		];

		foreach (array_merge($defaults, $properties) as $name => $value) {
			$property = $this->findProperty($reflection, $name);
			if ($property instanceof \ReflectionProperty) {
				$property->setAccessible(true);
				$property->setValue($form, $value);
			}
		}

		return $form;
	}

	private function findProperty(\ReflectionClass $reflection, string $name): ?\ReflectionProperty
	{
		for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
			if ($class->hasProperty($name)) {
				return $class->getProperty($name);
			}
		}

		return null;
	}

	private function init(ObjectForm $form): void
	{
		$method = new \ReflectionMethod(ObjectForm::class, 'init');
		$method->setAccessible(true);
		$method->invoke($form);
	}

	/** Read a property back off the form, whichever class in the chain declares it. */
	private function read(ObjectForm $form, string $name): mixed
	{
		$property = $this->findProperty(new \ReflectionClass(ObjectForm::class), $name);
		$property->setAccessible(true);

		return $property->getValue($form);
	}

	// ── The security control ─────────────────────────────────────────────────

	public function testRegistrationModeForcesAddOnly(): void
	{
		$form = $this->form(['register' => true]);

		$this->init($form);

		$this->assertTrue($this->read($form, 'addOnly'));
	}

	public function testAnIdInTheQueryStringCannotReachARegistrationForm(): void
	{
		// The attack this closes: /signup?id=admin on a public page. The
		// objectFetcher in this harness throws if fetchObject() is ever called,
		// so loading someone's record fails the test rather than passing it
		// quietly.
		$_GET['id'] = 'admin';

		$form = $this->form(['register' => true]);
		$this->init($form);

		$this->assertSame('', $this->read($form, 'id'));
	}

	public function testAnIdPassedAsAnOptionIsAlsoIgnored(): void
	{
		$form = $this->form(['register' => true, 'id' => 'admin']);

		$this->init($form);

		$this->assertSame('', $this->read($form, 'id'));
	}

	public function testARegistrationFormStaysAPostAndNeverBecomesAPut(): void
	{
		// /admin/register/{collection} has no PUT route, so a form that
		// switched method would fail at submit time — and switching only
		// happens when an existing object was loaded, which must not occur.
		$form = $this->form(['register' => true, 'id' => 'admin']);

		$this->init($form);

		$this->assertSame('POST', $this->read($form, 'method'));
	}

	// ── Where the form posts ─────────────────────────────────────────────────

	public function testRegistrationRetargetsAtThePublicEndpoint(): void
	{
		$form = $this->form(['register' => true]);

		$this->init($form);

		$this->assertSame('/admin/register/members', $this->read($form, 'route'));
	}

	public function testRegistrationDropsTheApiPrefix(): void
	{
		// /admin/register lives at the config base, not under the API prefix.
		// Getting this wrong posts the form at /api/admin/register/... and it
		// 404s for every visitor.
		$form = $this->form(['register' => true]);

		$this->init($form);

		$this->assertSame('/api', $this->read($form, 'api'));
	}

	// ── The ordinary admin form is unchanged ─────────────────────────────────

	// The generic addOnly behaviour — a normal form honouring $_GET['id'], an
	// addOnly form refusing it, POST vs PUT — is covered by
	// FormAddOnlySecurityTest. This file only holds what registration mode adds
	// on top: that it turns addOnly on for you, and where it posts.
}
