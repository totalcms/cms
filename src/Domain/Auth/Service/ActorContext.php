<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\Auth\Data\Actor;

/**
 * The actor the current request or run acts as. ObjectSaver, ObjectUpdater
 * and ObjectRemover read it on every write, so no entry point can write
 * without the access rules that apply to it.
 *
 * It fails closed. A web request starts as anonymous
 * (RequestScopeResetMiddleware calls {@see reset()} with a resolver that
 * reads the signed-in session user on first use), and the access
 * middleware, MCP authorizer or XML-RPC auth replace it once they know who
 * is calling. A path that forgets to set it is therefore enforced as
 * anonymous, never trusted. The CLI and the test bootstrap set
 * {@see Actor::system()} explicitly.
 *
 * Internal writes made on a caller's behalf but not by them (login
 * bookkeeping, automation and job runs, file cleanup) wrap themselves in
 * {@see runAs()} with the system actor. `runAs(` is the full list of
 * elevations.
 *
 * Not readonly: the actor is per-request state on a container singleton.
 */
final class ActorContext
{
	private ?Actor $actor = null;

	/** @var (\Closure(): Actor)|null */
	private ?\Closure $resolver = null;

	public function current(): Actor
	{
		if (!$this->actor instanceof Actor) {
			$this->actor = $this->resolver instanceof \Closure ? ($this->resolver)() : Actor::anonymous();
		}

		return $this->actor;
	}

	public function set(Actor $actor): void
	{
		$this->actor    = $actor;
		$this->resolver = null;
	}

	/**
	 * Start a new request: anonymous, or whoever $resolver returns the first
	 * time the actor is asked for. Lazy, so a request that never writes or
	 * checks access pays nothing for the lookup.
	 *
	 * @param (\Closure(): Actor)|null $resolver
	 */
	public function reset(?\Closure $resolver = null): void
	{
		$this->actor    = null;
		$this->resolver = $resolver;
	}

	/**
	 * Run $fn as $actor and restore the previous actor afterwards, even when
	 * $fn throws. The actor it replaces is recorded as $actor's initiator
	 * ({@see Actor::startedBy()}), so an event fired inside says the system
	 * acted for that member or admin, not just "system".
	 *
	 * @template T
	 *
	 * @param \Closure(): T $fn
	 *
	 * @return T
	 */
	public function runAs(Actor $actor, \Closure $fn): mixed
	{
		$initiator   = $this->current();
		$previous    = $this->actor;
		$resolver    = $this->resolver;
		$this->actor = $actor->initiator === null ? $actor->startedBy($initiator) : $actor;

		try {
			return $fn();
		} finally {
			$this->actor    = $previous;
			$this->resolver = $resolver;
		}
	}
}
