<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Media\Service\PublicUploadPolicy;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;

/** Resolver double returning fixed meta. */
final readonly class FixedMetaResolver extends PropertyMetaResolver
{
	/** @param array<string,mixed> $meta */
	public function __construct(private array $meta)
	{
	}

	public function resolve(string $collection, string $property, string $objectId = ''): array
	{
		return $this->meta;
	}
}

/** Resolver double naming a fixed owner property. */
final readonly class FixedOwnerResolver extends OwnerPropertyResolver
{
	public function __construct(private string $owner = '')
	{
	}

	public function resolve(string $collection): string
	{
		return $this->owner;
	}
}

/** @param array<string,mixed> $meta */
function publicUploadPolicy(object $test, array $meta = [], string $owner = ''): PublicUploadPolicy
{
	return new PublicUploadPolicy(new FixedMetaResolver($meta), new FileUploadValidator(), new FixedOwnerResolver($owner));
}

/** @param array<string,mixed> $grant */
function depotMeta(array $grant): array
{
	return ['field' => 'depot', 'settings' => ['publicUpload' => $grant]];
}

function publicGrant(array $extensions = ['pdf'], int $fileBytes = 1000, int $totalBytes = 5000): PublicUploadGrant
{
	return new PublicUploadGrant($extensions, $fileBytes, $totalBytes, null, false);
}

function publicTempFile(string $content): string
{
	$path = tempnam(sys_get_temp_dir(), 'pup');
	file_put_contents($path, $content);

	return $path;
}

test('no extensions means no grant', function (): void {
	expect(publicUploadPolicy($this, depotMeta(['extensions' => []]))->grantFor('c', 'p'))->toBeNull();
	expect(publicUploadPolicy($this, depotMeta([]))->grantFor('c', 'p'))->toBeNull();
	expect(publicUploadPolicy($this, depotMeta(['extensions' => ['html', 'php', 'svg']]))->grantFor('c', 'p'))->toBeNull();
});

test('a collection with an owner never gets a grant', function (string $owner): void {
	expect(publicUploadPolicy($this, depotMeta(['extensions' => ['pdf']]), $owner)->grantFor('c', 'p'))->toBeNull();
})->with(['owner', OwnerPropertyResolver::UNRESOLVED]);

test('a non-depot field never gets a grant', function (): void {
	$meta = ['field' => 'file', 'settings' => ['publicUpload' => ['extensions' => ['pdf']]]];
	expect(publicUploadPolicy($this, $meta)->grantFor('c', 'p'))->toBeNull();
});

test('grant defaults, MiB limits, normalized extensions and folder', function (): void {
	$grant = publicUploadPolicy($this, depotMeta(['extensions' => ['.PDF', 'Jpg', 'html'], 'comments' => true, 'folder' => '../incoming/']))->grantFor('c', 'p');
	expect($grant)->toBeInstanceOf(PublicUploadGrant::class)
		->and($grant->extensions)->toBe(['pdf', 'jpg'])
		->and($grant->maxFileBytes)->toBe(25 * 1024 * 1024)
		->and($grant->maxTotalBytes)->toBe(500 * 1024 * 1024)
		->and($grant->folder)->toBe('incoming')
		->and($grant->comments)->toBeTrue();

	$custom = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf'], 'maxFileMb' => 2, 'maxTotalMb' => 3]))->grantFor('c', 'p');
	expect($custom->maxFileBytes)->toBe(2 * 1048576)->and($custom->maxTotalBytes)->toBe(3 * 1048576)->and($custom->folder)->toBeNull();
});

test('invalid or non-positive limits fall back to defaults and empty folder is null', function (): void {
	$grant = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf'], 'maxFileMb' => 0, 'maxTotalMb' => 'big', 'folder' => '..', 'comments' => 'yes']))->grantFor('c', 'p');
	expect($grant->maxFileBytes)->toBe(25 * 1048576)
		->and($grant->maxTotalBytes)->toBe(500 * 1048576)
		->and($grant->folder)->toBeNull()
		->and($grant->comments)->toBeFalse();
	$neg = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf'], 'maxFileMb' => -4]))->grantFor('c', 'p');
	expect($neg->maxFileBytes)->toBe(25 * 1048576);
});

test('html and svg are refused even when listed', function (): void {
	$policy = publicUploadPolicy($this);
	$grant  = publicGrant(['html', 'svg', 'htm', 'xml', 'shtml', 'xhtml', 'php', 'pdf']);
	foreach (['a.html', 'a.svg', 'a.htm', 'a.xml', 'a.shtml', 'a.xhtml', 'a.php', 'a.HTML'] as $name) {
		expect($policy->extensionAllowed($grant, $name))->toBeFalse($name);
	}
	expect($policy->extensionAllowed($grant, 'a.pdf'))->toBeTrue();
	expect($policy->extensionAllowed($grant, 'a.zip'))->toBeFalse();
	expect($policy->extensionAllowed($grant, 'noextension'))->toBeFalse();
});

test('extension match ignores case', function (): void {
	expect(publicUploadPolicy($this)->extensionAllowed(publicGrant(['jpg']), 'PHOTO.JPG'))->toBeTrue();
});

test('php in a pdf name is refused after assembly', function (): void {
	$path = publicTempFile("<?php echo 'x';\n");
	try {
		expect(fn () => publicUploadPolicy($this)->assertAssembled(publicGrant(), $path, 0))->toThrow(UploadRejectedException::class);
	} finally {
		unlink($path);
	}
});

test('html content is refused after assembly but a clean file passes', function (): void {
	$html = publicTempFile('<!DOCTYPE html><html><body><script>1</script></body></html>');
	$txt  = publicTempFile('hello');
	try {
		expect(fn () => publicUploadPolicy($this)->assertAssembled(publicGrant(), $html, 0))->toThrow(UploadRejectedException::class);
		publicUploadPolicy($this)->assertAssembled(publicGrant(), $txt, 0);
		expect(true)->toBeTrue();
	} finally {
		unlink($html);
		unlink($txt);
	}
});

test('over maxFileMb and over maxTotalMb are refused', function (): void {
	$path = publicTempFile(str_repeat('a', 600));
	try {
		$policy = publicUploadPolicy($this);
		expect(fn () => $policy->assertAssembled(publicGrant(['pdf'], 500, 5000), $path, 0))->toThrow(UploadRejectedException::class);
		expect(fn () => $policy->assertAssembled(publicGrant(['pdf'], 1000, 1000), $path, 500))->toThrow(UploadRejectedException::class);
		$policy->assertAssembled(publicGrant(['pdf'], 1000, 1100), $path, 500);
		expect(true)->toBeTrue();
	} finally {
		unlink($path);
	}
});

test('a chosen tag is plain text on one line, up to 60 characters', function (): void {
	$policy = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf']]));

	expect($policy->cleanTag("  <b>Big</b>\n  Invoice "))->toBe('Big Invoice')
		->and(mb_strlen($policy->cleanTag(str_repeat('é', 100))))->toBe(60);
});

test('comment loses tags and stops at 1000 characters', function (): void {
	$policy = publicUploadPolicy($this);
	expect($policy->cleanComment('<b>Hi</b> ' . str_repeat('x', 2000)))->toHaveLength(1000)->not->toContain('<b>');
});

test('a less-than sign that opens no tag stays in a comment', function (string $sent, string $kept): void {
	expect(publicUploadPolicy($this)->cleanComment($sent))->toBe($kept);
})->with([
	['I <3 this site, please call', 'I <3 this site, please call'],
	['I <3 you', 'I <3 you'],
	['price < 100', 'price < 100'],
	['1<2 and 3>2', '1<2 and 3>2'],
	['ends with <', 'ends with <'],
]);

test('a tag is still removed from a comment', function (string $sent, string $kept): void {
	expect(publicUploadPolicy($this)->cleanComment($sent))->toBe($kept);
})->with([
	['<b>x</b>', 'x'],
	['<img src=x onerror=alert(1)>', ''],
	['<!-- hidden -->seen', 'seen'],
	['<?php echo 1; ?>seen', 'seen'],
	['<script>alert(1)</script>', 'alert(1)'],
	['<3 <b>bold</b>', '<3 bold'],
]);

test('no tag can be put together from what stripping leaves behind', function (string $sent): void {
	expect(publicUploadPolicy($this)->cleanComment($sent))->not->toMatch('/<[a-zA-Z\/!?]/');
})->with([
	'<<b>img src=x onerror=alert(1)>',
	'<<<b>script>alert(1)<<b>/script>',
	"<\0img src=x onerror=alert(1)>",
	'<<b>!-- x -->',
]);

test('comment cut never splits a multibyte character', function (): void {
	$clean = publicUploadPolicy($this)->cleanComment(str_repeat('é', 1500));
	expect(mb_strlen($clean))->toBe(1000)->and(mb_check_encoding($clean, 'UTF-8'))->toBeTrue();
});

test('prefixed name keeps the extension', function (): void {
	expect(publicUploadPolicy($this)->prefixedName('a.pdf'))->toMatch('/^[0-9a-f]{16}-a\.pdf$/');
});

test('depotBytes sums files recursively through folders', function (): void {
	$depot = [
		'files' => [
			['name' => 'a.pdf', 'size' => 100],
			['name' => 'in', 'mime' => 'folder', 'files' => [
				['name' => 'b.pdf', 'size' => 50],
				['name' => 'deep', 'mime' => 'folder', 'files' => [['name' => 'c.pdf', 'size' => 7]]],
			]],
		],
	];
	$policy = publicUploadPolicy($this);
	expect($policy->depotBytes($depot))->toBe(157)->and($policy->depotBytes([]))->toBe(0);
});

test('a name holding a control character is refused', function (string $name): void {
	$policy = publicUploadPolicy($this);
	expect($policy->extensionAllowed(publicGrant(), $name))->toBeFalse();
})->with(["a\0.pdf", "shell.php\0.pdf", "a\n.pdf", "a\r.pdf", "a\t.pdf", "a\x7F.pdf"]);

test('a blocked extension anywhere in the name is refused', function (string $name): void {
	$policy = publicUploadPolicy($this);
	expect($policy->extensionAllowed(publicGrant(), $name))->toBeFalse();
})->with(['shell.php.pdf', 'shell.PHP.pdf', 'page.html.pdf', 'a.b.svg.c.pdf', 'php.pdf', 'run.sh.pdf']);

test('an ordinary name with several dots is allowed', function (): void {
	$policy = publicUploadPolicy($this);
	expect($policy->extensionAllowed(publicGrant(), 'report.v2.final.pdf'))->toBeTrue();
});

test('assertAssembled refuses a file it cannot measure', function (): void {
	$policy = publicUploadPolicy($this);
	expect(fn () => $policy->assertAssembled(publicGrant(), sys_get_temp_dir() . '/no-such-file-' . uniqid(), 0))
		->toThrow(UploadRejectedException::class);
});

test('assertAssembled refuses a file it cannot read the type of', function (): void {
	$policy = publicUploadPolicy($this);
	$path   = publicTempFile('plain text');
	chmod($path, 0000);

	try {
		expect(fn () => $policy->assertAssembled(publicGrant(['pdf'], 1000, 5000), $path, 0))
			->toThrow(UploadRejectedException::class);
	} finally {
		chmod($path, 0600);
		unlink($path);
	}
});

test('a name is judged as it will be stored too', function (string $name): void {
	expect(publicUploadPolicy($this)->extensionAllowed(publicGrant(), $name))->toBeFalse();
})->with(['shell.php_.pdf', 'shell.php .pdf', 'shell.php-.pdf', 'shell.html_.pdf', "shell.\u{FF50}\u{FF48}\u{FF50}.pdf", "shell\u{FF0E}php.pdf"]);

test('a name that is merely untidy is still allowed', function (string $name): void {
	expect(publicUploadPolicy($this)->extensionAllowed(publicGrant(), $name))->toBeTrue();
})->with(['Quarterly Report.pdf', 'résumé (final).pdf', 'a_b-c.pdf']);

test('PHP handler extensions are blocked anywhere, and a grant listing them drops them', function (string $extension): void {
	$policy = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf', $extension]]));

	expect($policy->grantFor('c', 'p')->extensions)->toBe(['pdf'])
		->and($policy->extensionAllowed(publicGrant(['pdf', $extension]), "shell.{$extension}.pdf"))->toBeFalse()
		->and($policy->extensionAllowed(publicGrant(['pdf', $extension]), "shell.{$extension}"))->toBeFalse();
})->with(['phar', 'pht', 'phtm', 'phps', 'php6', 'php7', 'php8']);

test('the download name loses what could break a header or mislead a reader', function (string $sent, string $clean): void {
	expect(publicUploadPolicy($this)->downloadName($sent))->toBe($clean);
})->with([
	'quotes'     => ['a"b.pdf', 'ab.pdf'],
	'backslash'  => ['a\\b.pdf', 'ab.pdf'],
	'control'    => ["a\x01\x7Fb.pdf", 'ab.pdf'],
	'rtl'        => ["a\u{202E}b.pdf", 'ab.pdf'],
	'isolate'    => ["a\u{2066}b\u{2069}.pdf", 'ab.pdf'],
	'zero width' => ["a\u{200B}\u{FEFF}b.pdf", 'ab.pdf'],
	'accents'    => ['résumé.pdf', 'résumé.pdf'],
	'bad utf-8'  => ["a\xFFb.pdf", 'a?b.pdf'],
]);

test('the download name stops at 200 characters and keeps its extension', function (): void {
	$policy = publicUploadPolicy($this);

	$long = $policy->downloadName(str_repeat('é', 300) . '.pdf');
	expect(mb_strlen($long))->toBe(200)->and($long)->toEndWith('.pdf');

	$fits = str_repeat('a', 196) . '.pdf';
	expect($policy->downloadName($fits))->toBe($fits);
});

test('a fractional limit is kept and rounded up to whole bytes', function (): void {
	$grant = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf'], 'maxFileMb' => 0.5, 'maxTotalMb' => 1.0000001]))->grantFor('c', 'p');

	expect($grant->maxFileBytes)->toBe(524288)
		->and($grant->maxTotalBytes)->toBe((int)ceil(1.0000001 * 1048576));
});

test('a limit that is not a positive finite number falls back to the default', function (mixed $value): void {
	$grant = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf'], 'maxFileMb' => $value]))->grantFor('c', 'p');

	expect($grant->maxFileBytes)->toBe(25 * 1048576);
})->with([[0], [0.0], [-0.5], [NAN], [INF], ['2'], [null]]);

test('a huge name is refused before any cleaning and at once', function (): void {
	$policy = publicUploadPolicy($this);
	$name   = str_repeat('é', 32 * 1024) . '.pdf';

	$start   = hrtime(true);
	$allowed = $policy->extensionAllowed(publicGrant(), $name);
	$seconds = (hrtime(true) - $start) / 1e9;

	expect($allowed)->toBeFalse()->and($seconds)->toBeLessThan(0.05);
});

test('the length limit on a name falls at 255 bytes', function (): void {
	$policy = publicUploadPolicy($this);

	expect($policy->extensionAllowed(publicGrant(), str_repeat('a', 251) . '.pdf'))->toBeTrue()
		->and($policy->extensionAllowed(publicGrant(), str_repeat('a', 252) . '.pdf'))->toBeFalse();
});

test('a name may be 238 bytes, which leaves room for the stored prefix', function (): void {
	$policy = publicUploadPolicy($this);

	expect($policy->nameTooLong(str_repeat('a', 234) . '.pdf'))->toBeFalse()
		->and($policy->nameTooLong(str_repeat('a', 235) . '.pdf'))->toBeTrue()
		->and(strlen($policy->prefixedName(str_repeat('a', 234) . '.pdf')))->toBe(255);
});

test('a name that grows when cleaned for storage counts at its stored length', function (): void {
	expect(publicUploadPolicy($this)->nameTooLong(str_repeat('中', 70) . '.pdf'))->toBeTrue();
});

test('a huge name is called too long without being transliterated', function (): void {
	$start = hrtime(true);
	$long  = publicUploadPolicy($this)->nameTooLong(str_repeat('é', 500000) . '.pdf');

	expect($long)->toBeTrue()->and((hrtime(true) - $start) / 1e9)->toBeLessThan(0.05);
});

test('further script and page extensions are blocked anywhere, and a grant listing them drops them', function (string $extension): void {
	$policy = publicUploadPolicy($this, depotMeta(['extensions' => ['pdf', $extension]]));

	expect($policy->grantFor('c', 'p')->extensions)->toBe(['pdf'])
		->and($policy->extensionAllowed(publicGrant(['pdf', $extension]), "shell.{$extension}.pdf"))->toBeFalse()
		->and($policy->extensionAllowed(publicGrant(['pdf', $extension]), "shell.{$extension}"))->toBeFalse();
})->with(['php2', 'phpt', 'shtm', 'xht', 'svgz', 'mhtml']);
