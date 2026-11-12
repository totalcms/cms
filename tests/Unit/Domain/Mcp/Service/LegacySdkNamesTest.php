<?php

declare(strict_types=1);

use Mcp\Schema\Exception\ExceptionInterface;
use Mcp\Schema\MessageFactory;
use Mcp\Server\Capability\Registry\ReferenceHandler;
use Mcp\Server\Exception\PromptGetException;
use Mcp\Server\Exception\ToolCallException;
use TotalCMS\Domain\Mcp\Service\LegacySdkNames;

// Extensions written against mcp/sdk 0.9 name classes 0.10 moved. Names are
// strings here: the old ones only exist through the alias.

beforeEach(function (): void {
	LegacySdkNames::register();
});

it('maps each moved namespace to where 0.10 put the class', function (): void {
	expect(LegacySdkNames::newNameFor('Mcp\\Exception\\ToolCallException'))->toBe(ToolCallException::class)
		->and(LegacySdkNames::newNameFor('Mcp\\Exception\\PromptGetException'))->toBe(PromptGetException::class)
		->and(LegacySdkNames::newNameFor('Mcp\\Exception\\ExceptionInterface'))->toBe(ExceptionInterface::class)
		->and(LegacySdkNames::newNameFor('Mcp\\Capability\\Registry\\ReferenceHandler'))->toBe(ReferenceHandler::class)
		->and(LegacySdkNames::newNameFor('Mcp\\JsonRpc\\MessageFactory'))->toBe(MessageFactory::class);
});

it('leaves alone names it does not know', function (): void {
	expect(LegacySdkNames::newNameFor('Mcp\\Exception\\NoSuchException'))->toBeNull()
		->and(LegacySdkNames::newNameFor('Mcp\\Schema\\Tool'))->toBeNull()
		->and(LegacySdkNames::newNameFor('TotalCMS\\Domain\\Mcp\\Exception\\Anything'))->toBeNull();
});

it('lets an old-style throw be caught as the new class, the way the SDK catches it', function (): void {
	$old = 'Mcp\\Exception\\ToolCallException';

	try {
		throw new $old('Invoice not found.');
	} catch (ToolCallException $e) {
		expect($e->getMessage())->toBe('Invoice not found.')
			->and($e)->toBeInstanceOf($old)
			->and($e)->toBeInstanceOf('Mcp\\Exception\\ExceptionInterface');
	}
});
