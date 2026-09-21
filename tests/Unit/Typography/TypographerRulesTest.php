<?php

declare(strict_types=1);

use TotalCMS\Domain\Typography\Data\TypographyOptions;
use TotalCMS\Domain\Typography\Service\Typographer;

/**
 * One table per rule: the input, what comes out, and the option that turns
 * the rule on. widont is off in these tables so the expectations stay
 * readable; it has its own table below. Every row is also run twice to pin
 * idempotency — output must be a fixed point.
 */
function typographyProcess(string $input, array $options = []): string
{
	$options += ['widont' => false];

	return (new Typographer())->process($input, TypographyOptions::fromArray($options, 'en_US'));
}

/** @return array<string,array<int,mixed>> */
function typographyQuoteRows(): array
{
	return [
		'double quotes'                                 => ['He said "hello" to me', 'He said “hello” to me'],
		'at start and end'                              => ['"Quoted"', '“Quoted”'],
		'single quotes'                                 => ["She said 'hi'", 'She said ‘hi’'],
		'nested alternation'                            => ['"He said \'hi\' to me"', '“He said ‘hi’ to me”'],
		'contraction'                                   => ["don't do it", 'don’t do it'],
		'possessive'                                    => ["Joe's site", 'Joe’s site'],
		'plural possessive'                             => ["the dogs' bones", 'the dogs’ bones'],
		'decade elision'                                => ["the '90s", 'the ’90s'],
		'word elision'                                  => ["'tis the season, 'em all", '’tis the season, ’em all'],
		'rock n roll'                                   => ["rock 'n' roll", 'rock ’n’ roll'],
		'after opening bracket'                         => ['("quoted")', '(“quoted”)'],
		'after em dash'                                 => ['word—"quoted"', 'word—“quoted”'],
		'quote entities'                                => ['&quot;hello&quot; it&#39;s', '“hello” it’s'],
		'apostrophe wins over single open'              => ["'Twas the night", '’Twas the night'],
		'feet and inches'                               => ['He is 5\'10" tall', 'He is 5′10″ tall'],
		'inch after digit'                              => ['a 24" monitor', 'a 24″ monitor'],
		'closing quote after a number'                  => ['He said "I was 5"', 'He said “I was 5”'],
		'measurement closes an open quote (documented)' => ['"It\'s 24" wide," he said', '“It’s 24” wide,” he said'],
		'already curly is untouched'                    => ['“fine” ‘fine’ don’t', '“fine” ‘fine’ don’t'],
		'quotes off leaves quotes'                      => ['"x" it\'s', '"x" it’s', ['quotes' => false]],
		'primes off'                                    => ['24" wide', '24” wide', ['primes' => false]],
	];
}

test('quotes', function (string $in, string $out, array $options = []): void {
	expect(typographyProcess($in, $options))->toBe($out);
})->with(typographyQuoteRows());

/** @return array<string,array<int,mixed>> */
function typographyLocaleRows(): array
{
	return [
		'German'                 => ['de_DE', '"Hallo" und \'so\'', '„Hallo“ und ‚so‘'],
		'German language'        => ['de', '"Hallo"', '„Hallo“'],
		'Swiss German'           => ['de_CH', '"Grüezi"', '«Grüezi»'],
		'French'                 => ['fr_FR', '"Bonjour"', '«&#8239;Bonjour&#8239;»'],
		'Italian'                => ['it_IT', '"Ciao"', '«Ciao»'],
		'Polish'                 => ['pl_PL', '"Cześć"', '„Cześć”'],
		'Swedish'                => ['sv', '"Hej"', '”Hej”'],
		'Brazilian'              => ['pt_BR', '"Olá"', '“Olá”'],
		'Portuguese'             => ['pt_PT', '"Olá"', '«Olá»'],
		'hyphen tag'             => ['de-AT', '"Servus"', '„Servus“'],
		'unknown'                => ['xx_YY', '"?"', '“?”'],
		'apostrophe is always ’' => ['de_DE', "Joe's", 'Joe’s'],
	];
}

test('quote styles by locale', function (string $locale, string $in, string $out): void {
	expect(typographyProcess($in, ['quotes' => $locale]))->toBe($out);
})->with(typographyLocaleRows());

test('the filter default locale is used when quotes is unset or true', function (): void {
	$t = new Typographer();
	expect($t->process('"x"', TypographyOptions::fromArray([], 'de_DE')))->toBe('„x“')
		->and($t->process('"x"', TypographyOptions::fromArray(['quotes' => true], 'de_DE')))->toBe('„x“')
		->and($t->process('"x"', TypographyOptions::fromArray(['quotes' => 'en'], 'de_DE')))->toBe('“x”');
});

/** @return array<string,array<int,mixed>> */
function typographyDashRows(): array
{
	return [
		'spaced hyphen, em'      => ['one - two', 'one—two'],
		'spaced double, em'      => ['one -- two', 'one—two'],
		'double, em'             => ['one--two', 'one—two'],
		'triple, em'             => ['one---two', 'one—two'],
		'spaced hyphen, en'      => ['one - two', 'one – two', ['dashes' => 'en']],
		'double, en'             => ['one--two', 'one–two', ['dashes' => 'en']],
		'triple, en'             => ['one---two', 'one—two', ['dashes' => 'en']],
		'digit range'            => ['1990-2000 and 9-5', '1990–2000 and 9–5'],
		'page range'             => ['pp. 12-15', 'pp.&nbsp;12–15'],
		'ISO date untouched'     => ['on 2026-09-21', 'on 2026-09-21'],
		'part number untouched'  => ['part 555-1234-567', 'part 555-1234-567'],
		'word hyphen untouched'  => ['re-enter the T3-ready site', 're-enter the T3-ready site'],
		'minus before a number'  => ['it was -5 outside (-3 inside)', 'it was −5 outside (−3 inside)'],
		'hand-typed dash kept'   => ['one — two – three', 'one — two – three'],
		'dashes off'             => ['one -- two 1-2', 'one -- two 1-2', ['dashes' => false]],
	];
}

test('dashes', function (string $in, string $out, array $options = []): void {
	expect(typographyProcess($in, $options))->toBe($out);
})->with(typographyDashRows());

/** @return array<string,array<int,mixed>> */
function typographySymbolRows(): array
{
	return [
		'ellipsis'                => ['wait...', 'wait…'],
		'ellipsis off'            => ['wait...', 'wait...', ['ellipsis' => false]],
		'dimensions'              => ['1024x768 and 4 x 4', '1024×768 and 4 × 4'],
		'hex untouched'           => ['0x1F', '0x1F'],
		'x next to a letter'      => ['3xl and x2', '3xl and x2'],
		'plus minus'              => ['5 +- 1 and 5 +/- 1', '5 ± 1 and 5 ± 1'],
		'comparisons'             => ['a != b, a <= b, a >= b', 'a ≠ b, a ≤ b, a ≥ b'],
		'comparisons as entities' => ['a &lt;= b, a &gt;= b', 'a ≤ b, a ≥ b'],
		'arrows'                  => ['a -> b <- c', 'a → b ← c'],
		'arrows as entities'      => ['a -&gt; b &lt;- c', 'a → b ← c'],
		'math off'                => ['1024x768 ->', '1024x768 ->', ['math' => false]],
		'symbols'                 => ['(c) 2026 Total(TM) (R)', '© 2026 Total™ ®'],
		'symbols off'             => ['(c) 2026', '(c) 2026', ['symbols' => false]],
	];
}

test('ellipsis, math and symbols', function (string $in, string $out, array $options = []): void {
	expect(typographyProcess($in, $options))->toBe($out);
})->with(typographySymbolRows());

/** @return array<string,array<int,mixed>> */
function typographyNbspRows(): array
{
	return [
		'number and unit'        => ['10 kg at 25 % and 9 pm', '10&nbsp;kg at 25&nbsp;% and 9&nbsp;pm'],
		'English words stay'     => ['5 in the morning, 3 seconds', '5 in the morning, 3 seconds'],
		'abbreviations'          => ['Mr. Smith and Dr. Who, No. 5', 'Mr.&nbsp;Smith and Dr.&nbsp;Who, No.&nbsp;5'],
		'section sign'           => ['§ 4 and № 12', '§&nbsp;4 and №&nbsp;12'],
		'French punctuation'     => ['Vraiment ? Oui ! Non ; bien : sûr', 'Vraiment&#8239;? Oui&#8239;! Non&#8239;; bien&#8239;: sûr', ['quotes' => 'fr']],
		'French entity safe'     => ['a &amp; b ?', 'a &amp; b&#8239;?', ['quotes' => 'fr']],
		'French url colon safe'  => ['see https://x.test now', 'see https://x.test now', ['quotes' => 'fr']],
		'nbsp off'               => ['10 kg', '10 kg', ['nbsp' => false]],
	];
}

test('no-break spaces', function (string $in, string $out, array $options = []): void {
	expect(typographyProcess($in, $options))->toBe($out);
})->with(typographyNbspRows());

/** @return array<string,array<int,mixed>> */
function typographyWidontRows(): array
{
	return [
		'joins the last two words'  => ['one two three four', 'one two three&nbsp;four'],
		'two words are left alone'  => ['one two', 'one two'],
		'paragraphs'                => ['<p>one two three</p><p>four five six</p>', '<p>one two&nbsp;three</p><p>four five&nbsp;six</p>'],
		'trailing inline tag'       => ['<p>see the <a href="#">end</a>.</p>', '<p>see the&nbsp;<a href="#">end</a>.</p>'],
		'heading'                   => ['<h2>A Very Long Heading</h2>', '<h2>A Very Long&nbsp;Heading</h2>'],
		'list items'                => ['<ul><li>one two three</li><li>x y</li></ul>', '<ul><li>one two&nbsp;three</li><li>x y</li></ul>'],
		'not in a div'              => ['<div>one two three</div>', '<div>one two three</div>'],
		'already joined'            => ['one two three&nbsp;four', 'one two three&nbsp;four'],
	];
}

test('widont', function (string $in, string $out): void {
	expect((new Typographer())->process($in, TypographyOptions::fromArray([], 'en')))->toBe($out);
})->with(typographyWidontRows());

/** @return array<string,array<int,mixed>> */
function typographyMarkupRows(): array
{
	return [
		'fractions'               => ['1/2 cup and 3/4 tsp', '½ cup and ¾ tsp', ['fractions' => true]],
		'fractions skip dates'    => ['1/2/2026', '1/2/2026', ['fractions' => true]],
		'fractions off'           => ['1/2 cup', '1/2 cup'],
		'ordinals'                => ['1st, 2nd, 23rd, 4th', '1<sup>st</sup>, 2<sup>nd</sup>, 23<sup>rd</sup>, 4<sup>th</sup>', ['ordinals' => true]],
		'ordinals off'            => ['1st', '1st'],
		'wrap amp and caps'       => ['<p>NASA &amp; ESA</p>', '<p><span class="caps">NASA</span> <span class="amp">&amp;</span> <span class="caps">ESA</span></p>', ['wrap' => true]],
		'wrap opening quote'      => ['<p>"Hello" there</p>', '<p><span class="dquo">“</span>Hello” there</p>', ['wrap' => true]],
		'wrap not mid-block'      => ['<p>say <em>"hi"</em></p>', '<p>say <em>“hi”</em></p>', ['wrap' => true]],
		'wrap off'                => ['<p>NASA &amp; ESA</p>', '<p>NASA &amp; ESA</p>'],
	];
}

test('opt-in markup rules', function (string $in, string $out, array $options = []): void {
	expect(typographyProcess($in, $options))->toBe($out);
})->with(typographyMarkupRows());

test('every rule is idempotent: processing the output again changes nothing', function (): void {
	$t   = new Typographer();
	$all = ['quotes' => 'en', 'fractions' => true, 'ordinals' => true, 'wrap' => true];

	$tables = [
		'quotes'  => typographyQuoteRows(),
		'dashes'  => typographyDashRows(),
		'symbols' => typographySymbolRows(),
		'nbsp'    => typographyNbspRows(),
		'widont'  => typographyWidontRows(),
		'markup'  => typographyMarkupRows(),
	];
	foreach ($tables as $name => $rows) {
		foreach ($rows as $label => $row) {
			$options = TypographyOptions::fromArray(($row[2] ?? []) + $all, 'en_US');
			$once    = $t->process($row[0], $options);
			expect($t->process($once, $options))->toBe($once, "{$name}: {$label}");
		}
	}

	$french = TypographyOptions::fromArray(['quotes' => 'fr'], 'en_US');
	$once   = $t->process('"Vraiment" ? Oui !', $french);
	expect($once)->toBe('«&#8239;Vraiment&#8239;»&#8239;? Oui&#8239;!')
		->and($t->process($once, $french))->toBe($once);
});

test('options reject unknown keys and bad values', function (): void {
	expect(fn () => TypographyOptions::fromArray(['quote' => 'en'], 'en'))->toThrow(InvalidArgumentException::class, 'unknown option(s) quote')
		->and(fn () => TypographyOptions::fromArray(['dashes' => 'long'], 'en'))->toThrow(InvalidArgumentException::class)
		->and(fn () => TypographyOptions::fromArray(['quotes' => 5], 'en'))->toThrow(InvalidArgumentException::class);
});
