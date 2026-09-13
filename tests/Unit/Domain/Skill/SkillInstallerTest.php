<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Skill;

use TotalCMS\Domain\Skill\Service\SkillInstaller;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;

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

/** Build a minimal skill source tree and return its path. */
$makeSource = function (string $root): string {
	mkdir($root . '/references', 0755, true);
	file_put_contents($root . '/SKILL.md', implode("\n", [
		'---',
		'name: totalcms',
		'description: Use when building a Total CMS site.',
		'---',
		'',
		'# Building a Total CMS site',
		'',
		'Run `vendor/bin/tcms info` and read `vendor/totalcms/cms/resources/docs/`.',
	]));
	file_put_contents($root . '/references/data-model.md', "Objects live in `tcms-data/`.\n");
	file_put_contents($root . '/references/cli.md', "Run `vendor/bin/tcms list`.\n");

	return $root;
};

it('fingerprints a Composer install and a zip install of the same source identically', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$installer = new SkillInstaller();

	$composer = $installer->install($source, $this->tmp . '/composer', true, true);
	$zip      = $installer->install($source, $this->tmp . '/zip', true, false);

	expect($composer['hash'])->not->toBe('');
	expect($zip['hash'])->toBe($composer['hash']);
	expect($installer->fingerprint($source)['hash'])->toBe($composer['hash']);

	// The installed files themselves genuinely differ — the fingerprint is of the source.
	expect(file_get_contents($this->tmp . '/zip/SKILL.md'))
		->not->toBe(file_get_contents($this->tmp . '/composer/SKILL.md'));
});

it('stamps the installed copy with a sidecar manifest and frontmatter metadata', function () use ($makeSource): void {
	$source = $makeSource($this->tmp . '/source');
	$target = $this->tmp . '/target';

	$result = (new SkillInstaller())->install($source, $target, true, true);

	$manifest = json_decode((string)file_get_contents($target . '/' . SkillInstaller::MANIFEST), true);
	expect($manifest['hash'])->toBe($result['hash']);
	expect($manifest['layout'])->toBe('composer');
	expect($manifest['files'])->toHaveKey('references/data-model.md');
	expect($manifest['installedFor'])->toBe(Version::number());
	expect($manifest['installedAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

	$lines = explode("\n", (string)file_get_contents($target . '/SKILL.md'));
	expect($lines[0])->toBe('---');
	expect($lines[1])->toBe('name: totalcms');
	expect($lines[2])->toStartWith('description:');
	expect($lines[3])->toBe('metadata:');
	expect($lines[4])->toBe('  skill-hash: ' . $result['hash']);
	expect($lines[6])->toBe('  layout: composer');

	// The shipped source stays unstamped.
	expect(file_get_contents($source . '/SKILL.md'))->not->toContain('skill-hash');
	expect(file_exists($source . '/' . SkillInstaller::MANIFEST))->toBeFalse();
});

it('stamps a zip install with the zip layout', function () use ($makeSource): void {
	$source = $makeSource($this->tmp . '/source');
	$target = $this->tmp . '/target';

	(new SkillInstaller())->install($source, $target, true, false);

	expect(file_get_contents($target . '/SKILL.md'))->toContain('  layout: zip');
});

it('reports the installed skill as current right after install', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$target    = $this->tmp . '/target';
	$installer = new SkillInstaller();

	$installer->install($source, $target);
	$check = $installer->check($source, $target);

	expect($check['installed'])->toBeTrue();
	expect($check['current'])->toBeTrue();
	expect($check['installedHash'])->toBe($check['hash']);
	expect($check['installedFor'])->toBe(Version::number());
	expect($check['changed'])->toBe([]);
	expect($check['added'])->toBe([]);
	expect($check['removed'])->toBe([]);
});

it('goes stale and names the file when a source file is edited', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$target    = $this->tmp . '/target';
	$installer = new SkillInstaller();

	$installer->install($source, $target);
	file_put_contents($source . '/references/data-model.md', "Objects live in `tcms-data/`. Now with decks.\n");

	$check = $installer->check($source, $target);

	expect($check['installed'])->toBeTrue();
	expect($check['current'])->toBeFalse();
	expect($check['changed'])->toBe(['references/data-model.md']);
	expect($check['added'])->toBe([]);
	expect($check['removed'])->toBe([]);
	expect($check['installedHash'])->not->toBe($check['hash']);
});

it('lists a brand new source file as added and a deleted one as removed', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$target    = $this->tmp . '/target';
	$installer = new SkillInstaller();

	$installer->install($source, $target);
	file_put_contents($source . '/references/search.md', "Search providers.\n");
	unlink($source . '/references/cli.md');

	$check = $installer->check($source, $target);

	expect($check['current'])->toBeFalse();
	expect($check['added'])->toBe(['references/search.md']);
	expect($check['removed'])->toBe(['references/cli.md']);
});

it('falls back to the frontmatter stamp when the sidecar manifest is gone', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$target    = $this->tmp . '/target';
	$installer = new SkillInstaller();

	$result = $installer->install($source, $target);
	unlink($target . '/' . SkillInstaller::MANIFEST);

	$check = $installer->check($source, $target);

	expect($check['installed'])->toBeTrue();
	expect($check['current'])->toBeTrue();
	expect($check['installedHash'])->toBe($result['hash']);
	// No per-file detail survives without the sidecar, so nothing is listed as removed.
	expect($check['removed'])->toBe([]);
	expect($check['added'])->toBe(array_keys($installer->fingerprint($source)['files']));
});

it('reports not installed for a target with no sidecar and no stamp', function () use ($makeSource): void {
	$source = $makeSource($this->tmp . '/source');
	$target = $this->tmp . '/target';
	mkdir($target, 0755, true);
	file_put_contents($target . '/SKILL.md', "---\nname: totalcms\ndescription: x\n---\nbody");

	$check = (new SkillInstaller())->check($source, $target);

	expect($check['installed'])->toBeFalse();
	expect($check['current'])->toBeFalse();
	expect($check['installedHash'])->toBeNull();
	expect($check['changed'])->toBe([]);
});

it('refreshes the stamp instead of stacking a second one on re-install', function () use ($makeSource): void {
	$source    = $makeSource($this->tmp . '/source');
	$target    = $this->tmp . '/target';
	$installer = new SkillInstaller();

	$installer->install($source, $target);
	// force = false keeps the already-stamped file, so the stamp is rewritten in place.
	$installer->install($source, $target, false);

	$contents = (string)file_get_contents($target . '/SKILL.md');
	expect(substr_count($contents, 'metadata:'))->toBe(1);
	expect(substr_count($contents, 'skill-hash:'))->toBe(1);
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
