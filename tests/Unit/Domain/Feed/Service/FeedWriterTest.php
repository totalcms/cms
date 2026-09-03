<?php

declare(strict_types=1);

use TotalCMS\Domain\Feed\Service\FeedWriter;
use TotalCMS\Support\Config;

/**
 * FeedWriter turns a meta array plus a list of item arrays into an RSS or Atom
 * document. It is the engine behind `cms.feed.rss()` / `cms.feed.atom()`, so
 * the shapes asserted here are the public Twig contract.
 *
 * Everything is checked by parsing the emitted XML rather than by string
 * matching: a feed that does not parse is worthless however good it looks.
 */
function feedWriter(string $domain = 'example.com'): FeedWriter
{
	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->domain = $domain;

	return new FeedWriter($config);
}

/** @return array<string,mixed> */
function feedMeta(array $overrides = []): array
{
	return array_merge([
		'title'       => 'Total CMS Releases',
		'link'        => 'https://example.com/changelog',
		'description' => 'One entry per release.',
	], $overrides);
}

/** @return array<string,mixed> */
function feedItem(array $overrides = []): array
{
	return array_merge([
		'title'   => '3.5.0 — Total CMS becomes a platform',
		'link'    => 'https://example.com/changelog#v3-5-0',
		'id'      => 'v3-5-0',
		'date'    => '2026-08-26',
		'content' => '<p>Notes</p>',
	], $overrides);
}

function parseFeed(string $xml): SimpleXMLElement
{
	$prev = libxml_use_internal_errors(true);
	$doc  = simplexml_load_string($xml);
	libxml_use_internal_errors($prev);

	expect($doc)->not->toBeFalse('feed did not parse as XML');

	return $doc;
}

describe('FeedWriter RSS', function (): void {
	test('emits a parseable channel carrying the meta', function (): void {
		$xml = feedWriter()->write(feedMeta(), [feedItem()], 'rss');
		$rss = parseFeed($xml);

		expect((string)$rss->channel->title)->toBe('Total CMS Releases');
		expect((string)$rss->channel->link)->toBe('https://example.com/changelog');
		expect((string)$rss->channel->description)->toBe('One entry per release.');
	});

	test('emits one item per entry, in the order given', function (): void {
		$items = [
			feedItem(['title' => 'newest', 'id' => 'c', 'date' => '2026-08-26']),
			feedItem(['title' => 'middle', 'id' => 'b', 'date' => '2026-03-01']),
			feedItem(['title' => 'oldest', 'id' => 'a', 'date' => '2025-12-01']),
		];

		$rss = parseFeed(feedWriter()->write(feedMeta(), $items, 'rss'));

		// Ordering is the caller's job — they have |sortBy in the template —
		// so the writer must not reorder behind their back.
		$titles = [];
		foreach ($rss->channel->item as $item) {
			$titles[] = (string)$item->title;
		}
		expect($titles)->toBe(['newest', 'middle', 'oldest']);
	});

	test('carries HTML content through intact', function (): void {
		$html = '<p>Intro</p><ul><li><strong>CLI</strong> — scriptable</li></ul>';

		$rss  = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['content' => $html])], 'rss'));

		expect((string)$rss->channel->item[0]->description)->toBe($html);
	});

	test('survives a literal ]]> in the content', function (): void {
		// The hand-rolled template had to close and reopen the CDATA section
		// around this. If the writer handles it, templates never have to.
		$html = '<p>Use <code>a]]>b</code> in config</p>';

		$rss  = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['content' => $html])], 'rss'));

		expect((string)$rss->channel->item[0]->description)->toBe($html);
	});

	test('formats the date as RFC-2822, which RSS requires', function (): void {
		$rss = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['date' => '2026-08-26'])], 'rss'));

		expect((string)$rss->channel->item[0]->pubDate)->toContain('26 Aug 2026');
	});

	test('accepts a date as an ISO string, a timestamp, or a DateTime', function (): void {
		$stamp = strtotime('2026-08-26');

		foreach (['2026-08-26', $stamp, new DateTime('2026-08-26')] as $date) {
			$rss = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['date' => $date])], 'rss'));
			expect((string)$rss->channel->item[0]->pubDate)->toContain('26 Aug 2026');
		}
	});

	test('renders an empty feed rather than failing when there are no items', function (): void {
		$rss = parseFeed(feedWriter()->write(feedMeta(), [], 'rss'));

		expect($rss->channel->item->count())->toBe(0);
		expect((string)$rss->channel->title)->toBe('Total CMS Releases');
	});
});

describe('FeedWriter URLs', function (): void {
	test('makes a relative item link absolute against the configured domain', function (): void {
		// A reader has no base to resolve against, so a relative link is a
		// broken link rather than a slightly untidy one.
		$rss = parseFeed(feedWriter('totalcms.co')->write(feedMeta(), [feedItem(['link' => '/changelog#v3-5-0'])], 'rss'));

		expect((string)$rss->channel->item[0]->link)->toBe('https://totalcms.co/changelog#v3-5-0');
	});

	test('leaves an already-absolute link alone', function (): void {
		$rss = parseFeed(feedWriter('totalcms.co')->write(feedMeta(), [feedItem(['link' => 'https://elsewhere.test/x'])], 'rss'));

		expect((string)$rss->channel->item[0]->link)->toBe('https://elsewhere.test/x');
	});

	test('falls back to the link when an item has no id', function (): void {
		$item = feedItem();
		unset($item['id']);

		$rss = parseFeed(feedWriter()->write(feedMeta(), [$item], 'rss'));

		expect((string)$rss->channel->item[0]->guid)->toBe('https://example.com/changelog#v3-5-0');
	});
});

describe('FeedWriter media', function (): void {
	test('adds an enclosure from a plain URL, guessing the type', function (): void {
		$rss = parseFeed(feedWriter()->write(
			feedMeta(),
			[feedItem(['media' => 'https://example.com/audio/ep12.mp3'])],
			'rss',
		));

		$enclosure = $rss->channel->item[0]->enclosure;
		expect((string)$enclosure['url'])->toBe('https://example.com/audio/ep12.mp3');
		expect((string)$enclosure['type'])->toBe('audio/mpeg');
	});

	test('guesses image and pdf types, which the old table did not cover', function (): void {
		foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'] as $ext => $mime) {
			$rss = parseFeed(feedWriter()->write(
				feedMeta(),
				[feedItem(['media' => "https://example.com/f.{$ext}"])],
				'rss',
			));
			expect((string)$rss->channel->item[0]->enclosure['type'])->toBe($mime);
		}
	});

	test('takes an explicit type and length from the hash form', function (): void {
		// T3 image and file field values already carry mime and size, so a
		// template can hand over real numbers instead of a guess and a zero.
		$rss = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['media' => [
			'url'    => 'https://example.com/audio/ep12.bin',
			'type'   => 'audio/mpeg',
			'length' => 8675309,
		]])], 'rss'));

		$enclosure = $rss->channel->item[0]->enclosure;
		expect((string)$enclosure['type'])->toBe('audio/mpeg');
		expect((string)$enclosure['length'])->toBe('8675309');
	});

	test('makes a relative media url absolute too', function (): void {
		$rss = parseFeed(feedWriter('totalcms.co')->write(
			feedMeta(),
			[feedItem(['media' => '/depot/audio/ep12.mp3'])],
			'rss',
		));

		expect((string)$rss->channel->item[0]->enclosure['url'])->toBe('https://totalcms.co/depot/audio/ep12.mp3');
	});

	test('omits the enclosure entirely when no media is given', function (): void {
		$rss = parseFeed(feedWriter()->write(feedMeta(), [feedItem()], 'rss'));

		expect($rss->channel->item[0]->enclosure->count())->toBe(0);
	});
});

describe('FeedWriter Atom', function (): void {
	test('emits a parseable atom document from the same inputs', function (): void {
		$xml  = feedWriter()->write(feedMeta(['self' => 'https://example.com/feed.atom']), [feedItem()], 'atom');
		$atom = parseFeed($xml);

		expect($xml)->toContain('http://www.w3.org/2005/Atom');
		expect((string)$atom->title)->toBe('Total CMS Releases');
		expect($atom->entry->count())->toBe(1);
	});

	test('carries HTML content through atom as well', function (): void {
		// Atom does not carry HTML as a string the way RSS does: laminas
		// renders it into real XHTML nodes under content type="xhtml", and
		// keeps an escaped copy in summary. Assert the payload survived both
		// rather than assert a shape SimpleXML cannot give back.
		$xml = feedWriter()->write(
			feedMeta(['self' => 'https://example.com/feed.atom']),
			[feedItem(['content' => '<p>Intro</p>'])],
			'atom',
		);
		parseFeed($xml);

		expect($xml)->toContain('type="xhtml"');
		expect($xml)->toContain('<xhtml:p>Intro</xhtml:p>');
		expect($xml)->toContain('<summary type="html"><![CDATA[<p>Intro</p>]]></summary>');
	});

	test('uses the link as the entry id, because atom ids must be IRIs', function (): void {
		// `v3-5-0` is a fine RSS guid but not a legal atom id.
		$atom = parseFeed(feedWriter()->write(
			feedMeta(['self' => 'https://example.com/feed.atom']),
			[feedItem(['id' => 'v3-5-0'])],
			'atom',
		));

		expect((string)$atom->entry[0]->id)->toBe('https://example.com/changelog#v3-5-0');
	});

	test('keeps an http id when one is given', function (): void {
		$atom = parseFeed(feedWriter()->write(
			feedMeta(['self' => 'https://example.com/feed.atom']),
			[feedItem(['id' => 'https://example.com/releases/3-5-0'])],
			'atom',
		));

		expect((string)$atom->entry[0]->id)->toBe('https://example.com/releases/3-5-0');
	});

	test('falls back to the link for a non-http scheme rather than crashing', function (): void {
		// Laminas validates a `tag:` id via laminas-validator, which is not a
		// dependency — letting one reach the renderer throws at request time.
		$atom = parseFeed(feedWriter()->write(
			feedMeta(['self' => 'https://example.com/feed.atom']),
			[feedItem(['id' => 'tag:example.com,2026:v3-5-0'])],
			'atom',
		));

		expect((string)$atom->entry[0]->id)->toBe('https://example.com/changelog#v3-5-0');
	});

	test('rss keeps the short stable guid', function (): void {
		$rss = parseFeed(feedWriter()->write(feedMeta(), [feedItem(['id' => 'v3-5-0'])], 'rss'));

		expect((string)$rss->channel->item[0]->guid)->toBe('v3-5-0');
	});
});

describe('FeedWriter errors', function (): void {
	test('names the missing key rather than leaking a laminas error', function (): void {
		// A template author should learn which key they forgot, not read a
		// stack trace from a library they did not know they were using.
		$meta = feedMeta();
		unset($meta['description']);

		feedWriter()->write($meta, [feedItem()], 'rss');
	})->throws(DomainException::class, 'description');

	test('tells an atom caller that self is required, since readers re-fetch with it', function (): void {
		feedWriter()->write(feedMeta(), [feedItem()], 'atom');
	})->throws(DomainException::class, 'self');

	test('rejects an unknown format', function (): void {
		feedWriter()->write(feedMeta(), [feedItem()], 'jsonfeed');
	})->throws(DomainException::class, 'jsonfeed');
});
