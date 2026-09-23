<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Service\ExtensionEnableReviewer;

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/tcms-review-' . uniqid();
	mkdir($this->dir . '/skill/references', 0755, true);
	file_put_contents($this->dir . '/skill/SKILL.md', '# Use me');
	file_put_contents($this->dir . '/skill/references/a.md', 'a');
	file_put_contents($this->dir . '/extension.php', "<?php\n// nothing dangerous\n");
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->dir));
});

function reviewManifest(bool $bundled = false): ExtensionManifest
{
	return new ExtensionManifest('acme/thing', 'Thing', '', '1.0.0', [], 'extension.php', null, '', [], 'MIT', bundled: $bundled, reviewNote: 'Read this first');
}

test('risky capabilities are called out in display order with the review note and skill', function (): void {
	$review = (new ExtensionEnableReviewer())->review('acme/thing', reviewManifest(), [
		'mcp:tools'     => true,
		'twig:filters'  => true,
		'routes:public' => true,
	], $this->dir);

	expect(array_keys($review['risky']))->toBe(['routes:public', 'mcp:tools'])
		->and($review['risky']['routes:public'])->toBe('Exposes public, unauthenticated endpoints.')
		->and($review['hasFlags'])->toBeTrue()
		->and($review['reviewNote'])->toBe('Read this first')
		->and($review['skill']['files'])->toBe(['SKILL.md', 'references/a.md'])
		->and($review['skill']['contents'])->toBe('# Use me')
		->and($review['skill']['target'])->toStartWith('.claude/skills/');
});

test('nothing risky, no findings and no skill is a clean review', function (): void {
	exec('rm -rf ' . escapeshellarg($this->dir . '/skill'));

	$review = (new ExtensionEnableReviewer())->review('acme/thing', reviewManifest(bundled: true), ['twig:filters' => true], $this->dir);

	expect($review['risky'])->toBe([])
		->and($review['findings'])->toBe([])
		->and($review['hasFlags'])->toBeFalse()
		->and($review['skill'])->toBeNull();
});
