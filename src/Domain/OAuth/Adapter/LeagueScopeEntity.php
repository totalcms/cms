<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * Scope entity wrapping a single scope identifier from OAuthScopeRegistry.
 */
final class LeagueScopeEntity implements ScopeEntityInterface
{
	use ScopeTrait;
	use EntityTrait;

	public function __construct(string $identifier)
	{
		/** @var non-empty-string $id */
		$id               = $identifier;
		$this->identifier = $id;
	}
}
