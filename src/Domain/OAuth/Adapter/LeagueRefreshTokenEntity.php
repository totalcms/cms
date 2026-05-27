<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * Refresh-token entity. Composed from league's built-in traits.
 */
final class LeagueRefreshTokenEntity implements RefreshTokenEntityInterface
{
	use RefreshTokenTrait;
	use EntityTrait;
}
