<?php

declare(strict_types=1);

use Odan\Session\SessionInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Session\Service\SessionBootstrap;

// The host page's session data survives the PhpSession restart under
// `preserve` (the default) and is dropped under `replace` or anything else.
test('preserve carries the host session over', function (): void {
	expect(SessionBootstrap::carriedOver('preserve', ['cart' => [1, 2]]))->toBe(['cart' => [1, 2]]);
});

test('replace and unknown strategies drop it', function (string $strategy): void {
	expect(SessionBootstrap::carriedOver($strategy, ['cart' => [1, 2]]))->toBe([]);
})->with(['replace', 'bogus', '']);

test('nothing is resolved when no session is active', function (): void {
	expect((new SessionBootstrap(new NullLogger()))->resolveConflict('preserve'))->toBe([]);
});

test('restore writes every key back under its original name', function (): void {
	$session = test()->createMock(SessionInterface::class);
	$session->expects(test()->exactly(2))->method('set')
		->with(test()->logicalOr('cart', 'user'), test()->anything());

	(new SessionBootstrap(new NullLogger()))->restore($session, ['cart' => [1], 'user' => 'bob']);
});
