<?php

declare(strict_types=1);

namespace TotalCMS\Domain\OAuth\Adapter;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;

/**
 * League ClientEntityInterface wrapper around T3's OAuthClientData.
 */
final class LeagueClientEntity implements ClientEntityInterface
{
	use EntityTrait;
	use ClientTrait;

	public function __construct(OAuthClientData $data)
	{
		/** @var non-empty-string $id */
		$id                   = $data->id;
		$this->identifier     = $id;
		$this->name           = $data->name;
		$this->redirectUri    = $data->redirectUris;
		$this->isConfidential = $data->isConfidential;
	}
}
