<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

/**
 * Contract test for the one mcp/sdk internal behaviour T3 depends on.
 *
 * McpServerFactory::guardHandler() wraps a tool handler in a closure bound to
 * ReferenceHandler's scope. The SDK treats such a closure as "consumes the raw
 * argument bag" and skips its reflection + name-based parameter mapping — that
 * is what lets the guard read the caller's arguments (to resolve the target
 * collection for the access-group check) and then re-dispatch to the real
 * handler through the SDK's normal path. ExplicitElementLoader uses the same
 * trick, so this is a sanctioned mechanism rather than an accident.
 *
 * It is still SDK internals: mcp/sdk is pre-1.0, `composer.json` allows any
 * 0.7.x, and composer.lock is not tracked — so a fresh install or a
 * `composer update` can move the SDK underneath us. If that behaviour ever
 * changes, every requirement-bearing MCP tool breaks at once, and without this
 * test the failure surfaces as a pile of confusing tool errors that look like a
 * T3 authorization bug. This test makes it fail here instead, naming the real
 * cause.
 *
 * If this test fails after an SDK bump: the guard needs a different mechanism
 * for receiving raw arguments — do NOT weaken the authorization checks to make
 * it pass. See McpServerFactory::guardHandler().
 */
final class McpSdkContractTest extends TestCase
{
	public function testClosureBoundToReferenceHandlerScopeReceivesTheRawArgumentBag(): void
	{
		$received = null;

		$handler = \Closure::bind(
			static function (array $arguments) use (&$received): string {
				$received = $arguments;

				return 'ok';
			},
			null,
			ReferenceHandler::class,
		);

		$this->assertInstanceOf(\Closure::class, $handler);

		$arguments = [
			'collection' => 'blog',
			'id'         => 'first-post',
			'_session'   => $this->createMock(SessionInterface::class),
		];

		$result = (new ReferenceHandler())->handle(new ElementReference($handler), $arguments);

		$this->assertSame('ok', $result, 'The SDK did not invoke the bound closure.');
		$this->assertSame(
			$arguments,
			$received,
			'mcp/sdk no longer passes the raw argument bag to closures bound to '
			. 'ReferenceHandler scope. McpServerFactory::guardHandler() relies on '
			. 'this to read the target collection before enforcing access groups.',
		);
	}

	public function testUnboundClosureStillGetsReflectionBasedNamedBinding(): void
	{
		// The other half of the contract: an ordinary closure must keep receiving
		// named parameters, because the guard re-dispatches the REAL tool handler
		// through this path once its checks pass.
		$handler = static fn (string $collection, string $id): string => $collection . '/' . $id;

		$result = (new ReferenceHandler())->handle(
			new ElementReference($handler),
			[
				'collection' => 'blog',
				'id'         => 'first-post',
				'_session'   => $this->createMock(SessionInterface::class),
			],
		);

		$this->assertSame(
			'blog/first-post',
			$result,
			'mcp/sdk no longer binds named arguments for unbound closures. '
			. 'McpServerFactory::guardHandler() re-dispatches real tool handlers '
			. 'through this path after its authorization checks pass.',
		);
	}
}
