<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Exception\EntrypointLoadException;
use TotalCMS\Domain\Extension\Service\ExtensionEntrypointLoader;

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/tcms-ext-' . uniqid();
	mkdir($this->dir, 0755, true);
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->dir));
});

function loaderManifest(string $entrypoint): ExtensionManifest
{
	return new ExtensionManifest('acme/thing', 'Thing', '', '1.0.0', [], $entrypoint, null, '', [], 'MIT');
}

test('the class name is read from the file, skipping ::class fetches and comments', function (): void {
	file_put_contents($this->dir . '/ext.php', "<?php\nnamespace Acme\\Thing;\n// Foo::class in a comment\n\$x = \\Other::class;\n/** @var class-string */\nfinal class ThingExtension {}\n");

	expect(ExtensionEntrypointLoader::classNameIn($this->dir . '/ext.php'))->toBe('Acme\\Thing\\ThingExtension');
});

test('a file with no class, or that cannot be read, has no class name', function (): void {
	file_put_contents($this->dir . '/none.php', "<?php\nreturn 1;\n");

	expect(ExtensionEntrypointLoader::classNameIn($this->dir . '/none.php'))->toBeNull()
		->and(ExtensionEntrypointLoader::classNameIn($this->dir . '/missing.php'))->toBeNull();
});

test('a missing entrypoint is refused with the message the state records', function (): void {
	expect(fn () => (new ExtensionEntrypointLoader())->load(loaderManifest('extension.php'), $this->dir))
		->toThrow(EntrypointLoadException::class, 'Entrypoint not found: extension.php');
});

test('a class that is not an extension is refused after loading', function (): void {
	file_put_contents($this->dir . '/plain.php', "<?php\nnamespace Acme\\Plain" . uniqid() . ";\nclass NotAnExtension {}\n");

	expect(fn () => (new ExtensionEntrypointLoader())->load(loaderManifest('plain.php'), $this->dir))
		->toThrow(EntrypointLoadException::class, 'does not implement ExtensionInterface');
});

// Only the file up to its class declaration is tokenized (a bundled entry
// file can run to megabytes); anything that fools that prefix falls back to
// the whole file, so the answer never changes.

test('a class declared after a heredoc that mentions a class at line start is still found', function (): void {
	file_put_contents($this->dir . '/heredoc.php', "<?php\nnamespace Acme\\Doc;\n\$tpl = <<<TXT\nclass Fake extends Nothing\nTXT;\n\$s = \"\nclass Quoted\n\";\nfinal readonly class RealExtension {}\n");

	expect(ExtensionEntrypointLoader::classNameIn($this->dir . '/heredoc.php'))->toBe('Acme\\Doc\\RealExtension');
});

test('an attribute on the declaration line still resolves the class', function (): void {
	file_put_contents($this->dir . '/attr.php', "<?php\nnamespace Acme\\Attr;\n#[\\AllowDynamicProperties] class Tagged {}\n");

	expect(ExtensionEntrypointLoader::classNameIn($this->dir . '/attr.php'))->toBe('Acme\\Attr\\Tagged');
});

test('a class at the end of a large file is found', function (): void {
	$filler = str_repeat('function f' . uniqid() . "() { return 'x'; }\n", 20000);
	file_put_contents($this->dir . '/big.php', "<?php\nnamespace Acme\\Big;\n" . $filler . "abstract class Late {}\n");

	expect(ExtensionEntrypointLoader::classNameIn($this->dir . '/big.php'))->toBe('Acme\\Big\\Late');
});
