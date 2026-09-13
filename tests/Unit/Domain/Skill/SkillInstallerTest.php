<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Skill;

use TotalCMS\Domain\Skill\Service\SkillInstaller;
use TotalCMS\Support\PathResolver;

/**
 * The agent skill source ships in the package at resources/skill/ and is the
 * single source of truth — installed into customer projects by SkillInstaller.
 * These tests guard the copy behaviour and the integrity of the shipped source
 * (the lint that used to live in the now-removed bin/sync-skill.sh).
 */
beforeEach(function (): void {
	$this->tmp = sys_get_temp_dir() . '/tcms-skill-test-' . uniqid();
	mkdir($this->tmp, 0755, true);
});

afterEach(function (): void {
	$rm = function (string $dir) use (&$rm): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $rm($path) : unlink($path);
		}
		rmdir($dir);
	};
	$rm($this->tmp);
});

it('copies the skill tree from source into target', function (): void {
	$source = $this->tmp . '/source';
	$target = $this->tmp . '/target';
	mkdir($source . '/references', 0755, true);
	file_put_contents($source . '/SKILL.md', "---\nname: totalcms\ndescription: x\n---\nbody");
	file_put_contents($source . '/references/cli.md', 'cli');

	$result = (new SkillInstaller())->install($source, $target);

	expect($result['installed'])->toBeTrue();
	expect($result['failed'])->toBe([]);
	expect($result['copied'])->toContain('SKILL.md');
	expect(file_get_contents($target . '/SKILL.md'))->toContain('name: totalcms');
	expect(file_exists($target . '/references/cli.md'))->toBeTrue();
});

it('overwrites an existing install when force is true', function (): void {
	$source = $this->tmp . '/source';
	$target = $this->tmp . '/target';
	mkdir($source, 0755, true);
	mkdir($target, 0755, true);
	file_put_contents($source . '/SKILL.md', 'new');
	file_put_contents($target . '/SKILL.md', 'old');

	(new SkillInstaller())->install($source, $target, true);

	expect(file_get_contents($target . '/SKILL.md'))->toBe('new');
});

it('rewrites Composer paths in markdown for a zip install', function (): void {
	$source = $this->tmp . '/source';
	$target = $this->tmp . '/target';
	mkdir($source, 0755, true);
	file_put_contents($source . '/SKILL.md', implode("\n", [
		'Run `vendor/bin/tcms builder:routes` to list pages.',
		'Docs live at `vendor/totalcms/cms/resources/docs/fields/deck.md`.',
		'Composer install — CLI `vendor/bin/tcms`, docs `vendor/totalcms/cms/`. ' . SkillInstaller::KEEP_MARKER,
	]));
	file_put_contents($source . '/logo.svg', '<svg>vendor/bin/tcms</svg>');

	(new SkillInstaller())->install($source, $target, true, false);

	$skill = (string)file_get_contents($target . '/SKILL.md');
	expect($skill)->toContain('Run `php resources/bin/tcms builder:routes`');
	expect($skill)->toContain('Docs live at `resources/docs/fields/deck.md`.');
	expect($skill)->toContain('Composer install — CLI `vendor/bin/tcms`, docs `vendor/totalcms/cms/`.');

	// Non-markdown files are copied byte for byte.
	expect(file_get_contents($target . '/logo.svg'))->toBe('<svg>vendor/bin/tcms</svg>');
});

it('leaves Composer paths untouched for a Composer install', function (): void {
	$source   = $this->tmp . '/source';
	$target   = $this->tmp . '/target';
	$contents = "Run `vendor/bin/tcms info`, read `vendor/totalcms/cms/resources/docs/`.\n";
	mkdir($source, 0755, true);
	file_put_contents($source . '/SKILL.md', $contents);

	(new SkillInstaller())->install($source, $target, true, true);

	expect(file_get_contents($target . '/SKILL.md'))->toBe($contents);
});

it('defaults to the Composer layout when no layout is given', function (): void {
	$source = $this->tmp . '/source';
	$target = $this->tmp . '/target';
	mkdir($source, 0755, true);
	file_put_contents($source . '/SKILL.md', 'Run `vendor/bin/tcms info`.');

	(new SkillInstaller())->install($source, $target);

	expect(file_get_contents($target . '/SKILL.md'))->toBe('Run `vendor/bin/tcms info`.');
});

it('reports not installed when the source is missing rather than throwing', function (): void {
	$result = (new SkillInstaller())->install($this->tmp . '/does-not-exist', $this->tmp . '/target');

	expect($result['installed'])->toBeFalse();
	expect($result['copied'])->toBe([]);
});

it('ships a valid SKILL.md with name and description frontmatter', function (): void {
	$skill = PathResolver::packageRoot() . '/resources/skill/SKILL.md';
	expect(file_exists($skill))->toBeTrue();

	$head = implode("\n", array_slice(explode("\n", (string)file_get_contents($skill)), 0, 5));
	expect($head)->toMatch('/^name:/m');
	expect($head)->toMatch('/^description:/m');
});

it('rewrites the shipped skill source cleanly for a zip install', function (): void {
	$target = $this->tmp . '/zip';

	$result = (new SkillInstaller())->install(
		PathResolver::packageRoot() . '/resources/skill',
		$target,
		true,
		false,
	);

	expect($result['installed'])->toBeTrue();

	foreach ($result['copied'] as $relative) {
		if (!str_ends_with($relative, '.md')) {
			continue;
		}

		$contents = (string)file_get_contents($target . '/' . $relative);
		$lines    = array_filter(
			explode("\n", $contents),
			fn (string $line): bool => !str_contains($line, SkillInstaller::KEEP_MARKER),
		);
		$rewritten = implode("\n", $lines);

		// Nothing may still point at the Composer layout...
		expect($rewritten)->not->toContain('vendor/bin/tcms', "{$relative} still references vendor/bin/tcms");
		expect($rewritten)->not->toContain('vendor/totalcms/cms', "{$relative} still references vendor/totalcms/cms");
		// ...and the rewrite must not have left an empty `` code span behind.
		expect($rewritten)->not->toContain('``', "{$relative} has an empty code span after the rewrite");
	}
});

it('ships every reference file and links each from SKILL.md', function (): void {
	$dir      = PathResolver::packageRoot() . '/resources/skill';
	$contents = (string)file_get_contents($dir . '/SKILL.md');

	foreach (['cli', 'site-builder', 'frontend', 'data-model'] as $ref) {
		expect(file_exists($dir . '/references/' . $ref . '.md'))->toBeTrue("missing references/{$ref}.md");
		expect($contents)->toContain("references/{$ref}.md");
	}
});
