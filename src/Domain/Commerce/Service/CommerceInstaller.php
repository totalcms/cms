<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Data\InstallReport;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\PathResolver;

/**
 * Gives a site what Commerce stores its records in and the emails it sends.
 *
 * Safe to run any number of times: it creates what is missing and never
 * changes what is there. A collection of the right id on another schema is
 * the site's own, so it is reported and left alone; the operator points
 * `commerce.collections.{role}` at a free id instead. The one exception is
 * products: a store's own product schema, one that inherits
 * `commerce-product-base` at any depth, is a products collection too.
 */
final readonly class CommerceInstaller
{
	/**
	 * What the admin calls each kind of commerce record. The schema each is
	 * created on is CollectionRoles::SCHEMAS.
	 *
	 * @var array<string,array{name: string, singular: string}>
	 */
	private const COLLECTIONS = [
		'products'      => ['name' => 'Products', 'singular' => 'Product'],
		'orders'        => ['name' => 'Orders', 'singular' => 'Order'],
		'transactions'  => ['name' => 'Transactions', 'singular' => 'Transaction'],
		'carts'         => ['name' => 'Carts', 'singular' => 'Cart'],
		'subscriptions' => ['name' => 'Subscriptions', 'singular' => 'Subscription'],
		'discounts'     => ['name' => 'Discount codes', 'singular' => 'Discount code'],
	];

	/** The emails Commerce ships, each a file in resources/commerce/mailers. */
	private const MAILERS = ['commerce-receipt', 'commerce-payment-failed'];

	private const MAILER_COLLECTION = 'mailer';

	/**
	 * The group that may fetch a product's files through the site's ordinary
	 * download and stream addresses. Super admins always may.
	 */
	private const FILE_ACCESS_GROUP = 'admin';

	public function __construct(
		private CommerceSettings $settings,
		private CollectionFetcher $collections,
		private CollectionSaver $collectionSaver,
		private ObjectSaver $objects,
		private MailerFetcher $mailers,
		private ActorContext $actors,
		private CollectionRoles $roles,
	) {
	}

	public function install(): InstallReport
	{
		// Whoever runs it, the install is the site's own work.
		return $this->actors->runAs(Actor::system(), function (): InstallReport {
			$done = ['created' => [], 'existing' => [], 'errors' => []];

			foreach (array_keys(self::COLLECTIONS) as $role) {
				$this->record($done, $this->settings->collection($role), fn (string $id): string => $this->installCollection($role, $id));
			}
			foreach (self::MAILERS as $mailer) {
				$this->record($done, self::MAILER_COLLECTION . '/' . $mailer, fn (): string => $this->installMailer($mailer));
			}

			return new InstallReport($done['created'], $done['existing'], $done['errors']);
		});
	}

	/**
	 * Run one step and file its outcome. A step that fails is reported and
	 * the rest still run: one collection in the way does not strand the others.
	 *
	 * @param array{created: list<string>, existing: list<string>, errors: list<string>} $done
	 * @param \Closure(string): string                                                   $step returns 'created' or 'existing'
	 */
	private function record(array &$done, string $name, \Closure $step): void
	{
		try {
			$done[$step($name) === 'created' ? 'created' : 'existing'][] = $name;
		} catch (\Throwable $e) {
			$done['errors'][] = $e->getMessage();
		}
	}

	/** @throws \RuntimeException when a collection of that id is on another schema */
	private function installCollection(string $role, string $id): string
	{
		$wanted   = self::COLLECTIONS[$role];
		$existing = $this->collections->fetchCollection($id);

		if ($existing instanceof CollectionData) {
			if (!$this->roles->serves($role, $existing->schema)) {
				throw new \RuntimeException("Collection \"{$id}\" exists with schema \"{$existing->schema}\"; set commerce.collections.{$role} to another id.");
			}

			// An existing collection is left as it is, its File Access Groups too.
			return 'existing';
		}

		$this->collectionSaver->saveCollection([
			'id'               => $id,
			'schema'           => CollectionRoles::SCHEMAS[$role],
			'name'             => $wanted['name'],
			'labelPlural'      => $wanted['name'],
			'labelSingular'    => $wanted['singular'],
			'category'         => 'Commerce',
			// Nothing here is public until the operator says so. For orders,
			// transactions, carts, subscriptions and discount codes it never is.
			'publicOperations' => [],
			// File Access Groups. A product's files, the download a buyer pays
			// for among them, are closed to the public from the first upload.
			// The owner may change the groups; a buyer's own link asks for none.
			'groups'           => $role === 'products' ? [self::FILE_ACCESS_GROUP] : [],
		]);

		return 'created';
	}

	/** @throws \RuntimeException when the shipped template cannot be read or the mailer collection cannot be made */
	private function installMailer(string $id): string
	{
		if (!$this->collections->fetchOrCreateReserved(self::MAILER_COLLECTION) instanceof CollectionData) {
			throw new \RuntimeException("The mailer collection could not be created, so the {$id} email was not installed.");
		}

		// An email that is there may have been rewritten by the operator.
		if ($this->mailers->exists($id)) {
			return 'existing';
		}

		$file     = PathResolver::packageRoot() . "/resources/commerce/mailers/{$id}.json";
		$template = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		if (!is_array($template)) {
			throw new \RuntimeException("The {$id} email template is missing from this install.");
		}

		$this->objects->saveObject(self::MAILER_COLLECTION, ['id' => $id] + $template);

		return 'created';
	}
}
