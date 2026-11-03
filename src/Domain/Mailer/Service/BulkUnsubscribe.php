<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Support\Config;

/**
 * One-click unsubscribe for Bulk Send (RFC 8058).
 *
 * `$settings['mailer']['unsubscribeProperty']` (default `unsubscribed`) names
 * a boolean property on recipient objects. When the recipient collection's
 * schema has it, every bulk message carries `List-Unsubscribe` and
 * `List-Unsubscribe-Post` headers and an `unsubscribeUrl` Twig variable,
 * recipients with the property set are left out, and the link sets it.
 * Collections without the property send as before.
 *
 * Links never expire: they sit in old emails. The token names the collection
 * and object; the property is read from the settings when the link is used.
 */
readonly class BulkUnsubscribe
{
	public const PURPOSE = 'unsubscribe';

	/** The path of the page; the token follows it. */
	public const PATH = '/admin/unsubscribe/';

	/** The `{token}` a test send's link carries, which changes nothing. */
	public const PREVIEW = 'preview';

	public function __construct(
		private Config $config,
		private SchemaFetcher $schemas,
		private ObjectFetcher $objects,
		private ObjectPatcher $patcher,
		private ActorContext $actors,
		private SignedToken $tokens,
	) {
	}

	/**
	 * The unsubscribe property of $collection, or null when the setting is
	 * off or the collection's schema has no such property.
	 */
	public function property(string $collection): ?string
	{
		$property = trim((string)($this->config->mailer['unsubscribeProperty'] ?? 'unsubscribed'));
		if ($property === '') {
			return null;
		}

		try {
			$schema = $this->schemas->fetchSchemaForCollection($collection);
		} catch (\Throwable) {
			return null;
		}

		return isset($schema->properties[$property]) ? $property : null;
	}

	/** @param array<string,mixed> $object */
	public function isUnsubscribed(string $collection, array $object): bool
	{
		$property = $this->property($collection);

		return $property !== null && filter_var($object[$property] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	public function url(string $collection, string $objectId): string
	{
		return $this->base() . $this->tokens->sign(self::PURPOSE, ['c' => $collection, 'o' => $objectId]);
	}

	/** The link a test send carries: it shows the page and changes nothing. */
	public function previewUrl(): string
	{
		return $this->base() . self::PREVIEW;
	}

	/**
	 * The headers for one message, or [] when the collection has no
	 * unsubscribe property.
	 *
	 * @return array<string,string>
	 */
	public function headers(string $collection, string $objectId): array
	{
		if ($this->property($collection) === null) {
			return [];
		}

		return [
			'List-Unsubscribe'      => '<' . $this->url($collection, $objectId) . '>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		];
	}

	/**
	 * Whether $token is a link this site minted. It says nothing about
	 * whether the recipient still exists, so a page built on it reveals
	 * nothing about the collection.
	 */
	public function isValid(string $token): bool
	{
		return $token === self::PREVIEW || $this->payload($token) !== null;
	}

	/**
	 * Set the unsubscribe property on the object the link names. True when
	 * the token is valid, whether or not there was anything to change (the
	 * object is gone, the property was removed, or it is already set).
	 */
	public function unsubscribe(string $token): bool
	{
		if ($token === self::PREVIEW) {
			return true;
		}

		$payload = $this->payload($token);
		if ($payload === null) {
			return false;
		}

		[$collection, $objectId] = $payload;
		$property                = $this->property($collection);
		if ($property === null || !$this->objects->existsObject($collection, $objectId)) {
			return true;
		}

		// The site's own write: the recipient has no account to act as, and
		// the property may be protected or server-only.
		$this->actors->runAs(Actor::system(), fn () => $this->patcher->patchObject($collection, $objectId, [$property => true]));

		return true;
	}

	/** @return array{string,string}|null collection and object id */
	private function payload(string $token): ?array
	{
		$payload = $this->tokens->verify(self::PURPOSE, $token);
		if (!is_string($payload['c'] ?? null) || !is_string($payload['o'] ?? null) || $payload['c'] === '' || $payload['o'] === '') {
			return null;
		}

		return [$payload['c'], $payload['o']];
	}

	private function base(): string
	{
		return rtrim($this->config->url, '/') . $this->config->api . self::PATH;
	}
}
