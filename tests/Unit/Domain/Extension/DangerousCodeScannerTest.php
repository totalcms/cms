<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;

describe('DangerousCodeScanner', function (): void {
	$fixtureDir = __DIR__ . '/../../../fixtures/extensions/test-vendor/dangerous-ext';

	test('flags shell_exec', function () use ($fixtureDir): void {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		expect(array_column($findings, 'pattern'))->toContain('shell_exec');
	});

	test('flags network fetches and base64_decode', function () use ($fixtureDir): void {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('file_get_contents(http');
		expect($patterns)->toContain('base64_decode');
	});

	test('each finding has file, line, and snippet', function () use ($fixtureDir): void {
		$findings = (new DangerousCodeScanner())->scan($fixtureDir);
		expect($findings[0])->toHaveKeys(['pattern', 'file', 'line', 'snippet']);
	});

	test('a clean directory returns no findings', function (): void {
		$tmp = sys_get_temp_dir() . '/clean-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp);
		file_put_contents($tmp . '/Extension.php', "<?php\nfunction ok() { return 1 + 1; }\n");
		$findings = (new DangerousCodeScanner())->scan($tmp);
		expect($findings)->toBe([]);
	});

	test('skips vendor, node_modules and .git directories', function (): void {
		$tmp = sys_get_temp_dir() . '/vendor-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp . '/vendor/acme', 0777, true);
		mkdir($tmp . '/node_modules', 0777, true);
		mkdir($tmp . '/src', 0777, true);
		// Dangerous calls inside dependency dirs must NOT be flagged.
		file_put_contents($tmp . '/vendor/acme/lib.php', "<?php\nfunction x() { shell_exec('ls'); }\n");
		file_put_contents($tmp . '/node_modules/dep.php', "<?php\nfunction y() { eval('1'); }\n");
		// A real call in the author's own code IS flagged.
		file_put_contents($tmp . '/src/Real.php', "<?php\nfunction z() { base64_decode('a'); }\n");
		$findings = (new DangerousCodeScanner())->scan($tmp);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('base64_decode');
		expect($patterns)->not->toContain('shell_exec');
		expect($patterns)->not->toContain('eval');
	});

	test('patterns inside comments and string literals are ignored', function (): void {
		$tmp = sys_get_temp_dir() . '/comment-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp);
		$php = <<<'PHP'
			<?php
			// this mentions shell_exec('x') and a backtick `ls` in a line comment
			/* block comment with exec( and base64_decode( and `backticks` */
			# hash comment shell_exec(
			function ok(): string {
			    $note = 'the string shell_exec( and `ls` are not code';   // patterns inside a string literal
			    $url  = 'see file_get_contents( docs';
			    return $note . $url;
			}
			PHP;
		file_put_contents($tmp . '/Extension.php', $php);
		$findings = (new DangerousCodeScanner())->scan($tmp);
		expect($findings)->toBe([]);
	});

	test('real calls are flagged even when comments are nearby', function (): void {
		$tmp = sys_get_temp_dir() . '/real-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp);
		$php = <<<'PHP'
			<?php
			class Foo {
			    // run a real command below
			    public function go($svc): void {
			        shell_exec('ls'); // real call, comment alongside
			        $svc->exec();          // method call, NOT flagged
			    }
			    public function exec(): void {} // declaration, NOT flagged
			}
			PHP;
		file_put_contents($tmp . '/Extension.php', $php);
		$findings = (new DangerousCodeScanner())->scan($tmp);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('shell_exec');
		expect($patterns)->not->toContain('exec');
	});

	test('eval, backtick, and http file_get_contents are detected; local file_get_contents is not', function (): void {
		$tmp = sys_get_temp_dir() . '/lang-ext-' . bin2hex(random_bytes(4));
		mkdir($tmp);
		$php = <<<'PHP'
			<?php
			function go(): void {
			    eval('return 1;');
			    $out = `ls -la`;
			    $a = file_get_contents('https://evil.test/x');
			    $b = file_get_contents('/local/path');
			}
			PHP;
		file_put_contents($tmp . '/Extension.php', $php);
		$findings = (new DangerousCodeScanner())->scan($tmp);
		$patterns = array_column($findings, 'pattern');
		expect($patterns)->toContain('eval');
		expect($patterns)->toContain('backtick');
		expect($patterns)->toContain('file_get_contents(http');
		// Only ONE backtick finding (no double-count on the closing backtick).
		expect(count(array_keys($patterns, 'backtick', true)))->toBe(1);
		// Local file_get_contents is not flagged at all.
		expect($patterns)->not->toContain('file_get_contents');
	});

	test('scanCode flags an in-memory automation handler string', function (): void {
		$handler = <<<'PHP'
			<?php

			return function ($ctx) {
			    shell_exec('rm -rf /');

			    return ['ok' => true];
			};
			PHP;

		$findings = (new DangerousCodeScanner())->scanCode($handler);
		$patterns = array_column($findings, 'pattern');

		expect($patterns)->toContain('shell_exec');
		// Finding shape has no `file` key (string scan, not a directory walk).
		expect($findings[0])->toHaveKeys(['pattern', 'line', 'snippet']);
		expect($findings[0])->not->toHaveKey('file');
	});

	test('scanCode returns nothing for a benign handler', function (): void {
		$handler = <<<'PHP'
			<?php

			return function ($ctx) {
			    $ctx->objects->save('blog', ['title' => 'Hi']);

			    return ['saved' => 1];
			};
			PHP;

		expect((new DangerousCodeScanner())->scanCode($handler))->toBe([]);
	});

	test('scanCodeForBlocking returns only the shell/eval subset, not dual-use file/network calls', function (): void {
		$handler = <<<'PHP'
			<?php

			return function ($ctx) {
			    file_put_contents('/tmp/log', 'x');   // advisory only
			    curl_exec($ch);                        // advisory only
			    base64_decode('abc');                  // advisory only
			    exec('rm -rf /');                      // BLOCK
			    eval($code);                           // BLOCK
			};
			PHP;

		$scanner = new DangerousCodeScanner();

		// The advisory scan still surfaces everything.
		$allPatterns = array_column($scanner->scanCode($handler), 'pattern');
		expect($allPatterns)->toContain('file_put_contents')->toContain('exec')->toContain('eval');

		// The blocking scan keeps only the shell/eval primitives.
		$blockingPatterns = array_column($scanner->scanCodeForBlocking($handler), 'pattern');
		expect($blockingPatterns)->toContain('exec')->toContain('eval');
		expect($blockingPatterns)->not->toContain('file_put_contents');
		expect($blockingPatterns)->not->toContain('curl_exec');
		expect($blockingPatterns)->not->toContain('base64_decode');
	});
});
