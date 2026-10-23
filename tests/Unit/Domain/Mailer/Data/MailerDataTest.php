<?php

declare(strict_types=1);

use TotalCMS\Domain\Mailer\Data\MailerData;

it('reads requireBotProtection as a boolean, off by default', function (mixed $stored, bool $expected): void {
	$data = $stored === null ? ['id' => 'm'] : ['id' => 'm', 'requireBotProtection' => $stored];

	$mailer = MailerData::fromArray($data);

	expect($mailer->requireBotProtection)->toBe($expected);
	expect($mailer->toArray()['requireBotProtection'])->toBe($expected);
})->with([
	'missing'       => [null, false],
	'true'          => [true, true],
	'false'         => [false, false],
	'string true'   => ['true', true],
	'string false'  => ['false', false],
]);
