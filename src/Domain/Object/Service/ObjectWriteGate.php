<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;

/**
 * The access rules at the point of storage. ObjectSaver, ObjectUpdater and
 * ObjectRemover call it on every write with the current actor from
 * {@see ActorContext}, so an entry point that forgets its own check (a new
 * route, an MCP tool, an extension) still cannot claim another member's
 * object, set a protected field, or let an API key limited to some
 * collections write to another.
 *
 * Entry points still check first: they answer with a proper refusal, while
 * a refusal here is an {@see AccessDeniedException} (403) that means the
 * entry point let through something it should not have. Each refusal is
 * recorded first ({@see AccessDenials}), so it reaches the access log and
 * any `access.denied` listener.
 */
final readonly class ObjectWriteGate
{
	public function __construct(
		private ActorContext $actors,
		private AccessGuard $guard,
		private AccessDenials $denials,
	) {
	}

	/**
	 * The payload of a new object, with the owner stamped and protected and
	 * forced fields applied for the current actor.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function beforeCreate(string $collection, array $data): array
	{
		$this->requireReach($collection, (string)($data['id'] ?? ''), Operation::Create);

		return $this->guard->applyWrite($this->actors->current(), $collection, (string)($data['id'] ?? ''), $data);
	}

	/**
	 * The payload of an update, after checking the current actor owns the
	 * object, with protected fields reverted and forced fields applied.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function beforeUpdate(string $collection, string $id, array $data): array
	{
		$this->requireReach($collection, $id, Operation::Update);
		$this->requireOwner($collection, $id, Operation::Update);

		return $this->guard->applyWrite($this->actors->current(), $collection, $id, $data);
	}

	/**
	 * Refuse a delete of an object the current actor does not own.
	 */
	public function beforeDelete(string $collection, string $id): void
	{
		$this->requireReach($collection, $id, Operation::Delete);
		$this->requireOwner($collection, $id, Operation::Delete);
	}

	/**
	 * Refuse a write by an API key whose paths do not cover the collection
	 * ({@see Actor::reaches()}). Every other
	 * actor reaches every collection here; its groups are the entry point's
	 * check.
	 */
	private function requireReach(string $collection, string $id, Operation $operation): void
	{
		$actor = $this->actors->current();
		if ($actor->reaches($collection, $operation->groupOperation())) {
			return;
		}

		$this->denials->write($actor, $collection, $id, $operation, Decision::DenyGroup);

		throw new AccessDeniedException(sprintf('This API key may not %s objects in "%s".', $operation->value, $collection));
	}

	private function requireOwner(string $collection, string $id, Operation $operation): void
	{
		$actor    = $this->actors->current();
		$decision = $this->guard->ownerDecision($actor, $collection, $operation, $id);
		if ($decision !== Decision::Allow) {
			$this->denials->write($actor, $collection, $id, $operation, $decision);

			throw new AccessDeniedException(sprintf('You may not %s object "%s" in "%s".', $operation->value, $id, $collection));
		}
	}
}
