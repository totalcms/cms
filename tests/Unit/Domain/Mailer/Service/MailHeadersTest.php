<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use TotalCMS\Domain\Mailer\Service\MailHeaders;

/*
 * Extra headers a caller adds to one message: List-Unsubscribe for bulk
 * mail, a campaign id, a feedback id. Anything that could rewrite the
 * envelope or inject a header is dropped, and the caller is told which.
 */

function headersOn(mixed $headers): array
{
	$mail    = new PHPMailer(true);
	$skipped = MailHeaders::apply($mail, $headers);

	return [$mail->getCustomHeaders(), $skipped];
}

it('adds each header to the message', function (): void {
	[$set, $skipped] = headersOn([
		'List-Unsubscribe'      => '<https://example.com/u/abc>',
		'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		'X-Campaign'            => 'spring',
	]);

	expect($set)->toBe([
		['List-Unsubscribe', '<https://example.com/u/abc>'],
		['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'],
		['X-Campaign', 'spring'],
	])->and($skipped)->toBe([]);
});

it('drops a value with a line break', function (): void {
	[$set, $skipped] = headersOn(['X-Note' => "ok\r\nBcc: victim@example.com", 'X-Other' => "a\nb"]);

	expect($set)->toBe([])->and($skipped)->toBe(['X-Note', 'X-Other']);
});

it('drops a name that is not a header token', function (): void {
	[$set, $skipped] = headersOn(['Bad Name' => 'x', 'X:Colon' => 'x', '' => 'x', 'X-Good' => 'y']);

	expect($set)->toBe([['X-Good', 'y']])->and($skipped)->toBe(['Bad Name', 'X:Colon', '']);
});

it('drops headers the mailer sets itself, whatever their case', function (): void {
	[$set, $skipped] = headersOn(['from' => 'x@example.com', 'BCC' => 'y@example.com', 'Content-Type' => 'text/plain', 'Subject' => 'x', 'X-Fine' => '1']);

	expect($set)->toBe([['X-Fine', '1']])->and($skipped)->toBe(['from', 'BCC', 'Content-Type', 'Subject']);
});

it('drops values that are not strings', function (): void {
	[$set, $skipped] = headersOn(['X-Num' => 5, 'X-List' => ['a']]);

	expect($set)->toBe([])->and($skipped)->toBe(['X-Num', 'X-List']);
});

it('ignores a headers value that is not a map', function (mixed $headers): void {
	expect(headersOn($headers))->toBe([[], []]);
})->with([[null], ['X-Foo: bar'], [[]]]);
