<?php

declare(strict_types=1);

use TotalCMS\Domain\OAuth\Service\OAuthTtl;

describe('OAuthTtl', function (): void {
	it('converts a DateInterval spec to seconds', function (string $spec, int $seconds): void {
		expect(OAuthTtl::seconds($spec, 1))->toBe($seconds);
	})->with([
		'15 minutes' => ['PT15M', 900],
		'1 hour'     => ['PT1H', 3600],
		'12 hours'   => ['PT12H', 43200],
		'1 day'      => ['P1D', 86400],
		'30 days'    => ['P30D', 2592000],
		'180 days'   => ['P180D', 15552000],
		'1 year'     => ['P1Y', 31536000],
	]);

	it('falls back for a missing, empty or invalid spec', function (mixed $spec): void {
		expect(OAuthTtl::seconds($spec, 3600))->toBe(3600);
	})->with([
		'null'       => [null],
		'empty'      => [''],
		'not ISO'    => ['1 hour'],
		'zero'       => ['PT0S'],
		'non-string' => [3600],
	]);
});
