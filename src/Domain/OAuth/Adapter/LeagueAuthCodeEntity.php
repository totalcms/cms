<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Short-lived authorization-code entity. Composed from league's built-in traits.
 */
final class LeagueAuthCodeEntity implements AuthCodeEntityInterface
{
	use AuthCodeTrait;
	use TokenEntityTrait;
	use EntityTrait;
}
