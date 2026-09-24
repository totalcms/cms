<?php

declare(strict_types=1);

use TotalCMS\Domain\Mcp\Auth\Data\McpCallerKind;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpInstructions;

/**
 * The initialize-time instructions are the skill's judgment for MCP-only
 * clients. They must carry the modelling and writing rules, point at the docs
 * tools, and not tell a read-only connection how to write.
 */
test('every persona is oriented and pointed at the docs tools and prompts', function (): void {
	foreach (McpPersona::cases() as $persona) {
		$text = McpInstructions::for($persona);

		expect($text)->toContain('list_collections')->toContain('docs_search')->toContain('docs_lookup')->toContain('tcms_*')
			->toContain('collection (not table)');
	}
});

test('the public persona is told it can only read, and nothing about writing', function (): void {
	$text = McpInstructions::for(McpPersona::PUBLIC_);

	expect($text)->toContain('can only read')->toContain('API key')
		->not->toContain('patch_object')->not->toContain('create_schema');
});

test('the admin persona gets the writing and modelling rules', function (): void {
	$text = McpInstructions::for(McpPersona::ADMIN);

	expect($text)->toContain('patch_object')->toContain('send only the fields you change')->toContain('Never invent ids')
		->toContain('text is the last resort')->toContain('integer for whole numbers')->toContain('seo card')
		->toContain('reserved totalcms schema');
});

test('the authenticated persona writes within its grants and is not handed the admin modelling rules', function (): void {
	$text = McpInstructions::for(McpPersona::AUTHENTICATED);

	expect($text)->toContain('scopes and access groups')->toContain('send only the fields you change')
		->not->toContain('create_schema');
});

test('it stays short enough to prepend to every conversation', function (): void {
	foreach (McpPersona::cases() as $persona) {
		expect(str_word_count(McpInstructions::for($persona)))->toBeLessThan(420);
	}
});

test('a session caller is told it reads only, whatever its persona', function (): void {
	foreach ([McpPersona::ADMIN, McpPersona::AUTHENTICATED, McpPersona::PUBLIC_] as $persona) {
		$text = McpInstructions::for($persona, McpCallerKind::Session);

		expect($text)->toContain('browser session')->toContain('only read')->toContain('API key')
			->not->toContain('patch_object')->not->toContain('create_schema');
	}
});

test('the kind defaults to anonymous and changes nothing for existing callers', function (): void {
	expect(McpInstructions::for(McpPersona::ADMIN))->toBe(McpInstructions::for(McpPersona::ADMIN, McpCallerKind::ApiKey));
});
