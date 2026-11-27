<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Tests\Fakes\ClosedCommerceGate;
use Tests\Fakes\FakeDownloadSource;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Download\DownloadLinks;
use TotalCMS\Domain\Commerce\Download\DownloadProtection;
use TotalCMS\Domain\Commerce\Download\DownloadSources;
use TotalCMS\Domain\Commerce\Download\FileDownloadSource;
use TotalCMS\Domain\Commerce\Download\UrlDownloadSource;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/*
 * Digital delivery: a product's file reaches the person who paid for it,
 * and nobody else.
 *
 * A digital product has one file (its Download). When an order is paid, each
 * line whose product has a file is delivered: the buyer's order page and
 * their receipt carry a signed link that expires, and each line can be
 * downloaded a set number of times. The file itself is never served by the
 * site's ordinary download and stream addresses.
 *
 * The guide is a $9.00 download with a file. The course is a $40.00
 * download with no file: something else (a license key, a person) delivers
 * it. The mug ships.
 */

beforeEach(function (): void {
	// The two digital products and the guide's file are the same for every
	// test here: the first test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('digital-delivery');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	$this->asStore = fn (Closure $work) => $this->c->get(ActorContext::class)->runAs(Actor::system(), $work);
	$stock && ($this->asStore)(function (): void {
		$save = fn (array $product) => $this->c->get(ObjectSaver::class)->saveObject('products', $product);
		$save(['id' => 'guide', 'title' => 'Guide', 'status' => 'active', 'type' => 'digital', 'price' => 9, 'requiresShipping' => false]);
		$save(['id' => 'course', 'title' => 'Course', 'status' => 'active', 'type' => 'digital', 'price' => 40, 'requiresShipping' => false]);
	});

	// Give a product its file.
	$this->attach = function (string $productId, string $name = 'guide.pdf', string $bytes = '%PDF-1.4 the guide'): void {
		$path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
		file_put_contents($path, $bytes);
		rename($path, dirname($path) . '/' . $name) && $path = dirname($path) . '/' . $name;
		($this->asStore)(fn () => $this->c->get(FileSaver::class)->save('products', $productId, 'download', $path));
	};
	if ($stock) {
		($this->attach)('guide');
		keepCommerceScene('digital-delivery');
	}

	// An order for these items, paid unless told otherwise.
	$this->order = function (array $items = ['guide' => 1], bool $paid = true): OrderData {
		$order = $this->orders->create(commerceDraft($items), 'fake');
		if ($paid) {
			$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $order->grandTotal(), 'evt_' . $order->number()));
		}

		return $this->orders->find($order->id());
	};
	$this->fresh = fn (OrderData $order): OrderData => $this->orders->find($order->id());
	$this->links = fn (OrderData $order): array => $this->c->get(DownloadLinks::class)->for(($this->fresh)($order));
	$this->line  = fn (OrderData $order, string $productId): array => array_values(array_filter(($this->fresh)($order)->lines(), static fn (array $line): bool => $line['productId'] === $productId))[0];
	// Keep the store's products in a collection on a schema that redefines download as the given field.
	$this->downloadIs = fn (string $field, array $parts = ['base', 'digital', 'inventory', 'subscription', 'variants']) => commerceDownloadIs($this->c, $field, $parts);
	// The sources, with a logger whose lines the test can read.
	$this->logged = function () {
		$handler = new TestHandler();
		$logger  = new Logger('commerce', [$handler]);
		$factory = new class($logger) extends LoggerFactory {
			public function __construct(private readonly LoggerInterface $logger)
			{
			}

			public function channelLogger(LogChannel $channel, ?Level $level = null): LoggerInterface
			{
				return $this->logger;
			}
		};

		return [new DownloadSources($this->c->get(FileDownloadSource::class), new UrlDownloadSource(), $factory, $this->c->get(SchemaFetcher::class)), $handler];
	};
	// The path of a link, as the test client asks for it.
	$this->path = fn (string $url): string => (string)parse_url($url, PHP_URL_PATH);
});

describe('when an order is paid', function (): void {
	it('delivers a line whose product has a file', function (): void {
		$order = ($this->order)();

		expect(($this->line)($order, 'guide')['fulfillmentStatus'])->toBe('fulfilled')
			->and($order->fulfillmentStatus())->toBe('fulfilled')
			// Nothing else to do for it: the order is complete.
			->and($order->status())->toBe('completed');
	});

	it('leaves a digital line with no file for whoever delivers it', function (): void {
		$order = ($this->order)(['course' => 1]);

		expect(($this->line)($order, 'course')['fulfillmentStatus'])->toBe('unfulfilled')
			->and($order->status())->toBe('open');
	});

	it('leaves a line that ships alone', function (): void {
		$order = ($this->order)(['guide' => 1, 'mug' => 1]);

		expect(($this->line)($order, 'guide')['fulfillmentStatus'])->toBe('fulfilled')
			->and(($this->line)($order, 'mug')['fulfillmentStatus'])->toBe('unfulfilled')
			->and($order->fulfillmentStatus())->toBe('partial');
	});

	it('delivers it later when the file is added and fulfillment is run again', function (): void {
		$order = ($this->order)(['course' => 1]);
		($this->attach)('course', 'course.zip', 'PK the course');

		$this->c->get(OrderAdmin::class)->refulfill($order->id());

		expect(($this->line)($order, 'course')['fulfillmentStatus'])->toBe('fulfilled')
			->and(($this->fresh)($order)->status())->toBe('completed')
			->and(($this->links)($order))->toHaveCount(1);
	});
});

describe('the links', function (): void {
	it('is one for each delivered line: its file, how many downloads are left, and a signed address', function (): void {
		$order = ($this->order)(['guide' => 1, 'course' => 1, 'mug' => 1]);
		$links = ($this->links)($order);

		expect($links)->toHaveCount(1)
			->and($links[0]['lineId'])->toBe(array_key_first(array_filter($order->lines(), static fn (array $l): bool => $l['productId'] === 'guide')))
			->and($links[0]['title'])->toBe('Guide')
			->and($links[0]['fileName'])->toBe('guide.pdf')
			->and($links[0]['size'])->toBe(strlen('%PDF-1.4 the guide'))
			->and($links[0]['remaining'])->toBe(5)
			->and($links[0]['url'])->toContain('/commerce/download/');
	});

	it('has none for an order nobody has paid for', function (): void {
		expect(($this->links)(($this->order)(['guide' => 1], false)))->toBe([]);
	});

	it('has none once the money has all gone back', function (): void {
		$order = ($this->order)();
		$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $order->id(), $order->grandTotal(), 'evt_refund'));

		expect(($this->fresh)($order)->paymentStatus())->toBe('refunded')
			->and(($this->links)($order))->toBe([]);
	});

	it('still has them after part of the money went back', function (): void {
		$order = ($this->order)();
		$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $order->id(), 100, 'evt_refund'));

		expect(($this->links)($order))->toHaveCount(1);
	});

	it('lasts as long as the store says, from when it is made', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['downloadTtlHours' => 2] + $config->commerce;
		$token            = basename(($this->links)(($this->order)())[0]['url']);

		$soon  = new SignedToken(null, static fn (): int => time() + 7100);
		$later = new SignedToken(null, static fn (): int => time() + 7300);

		expect($soon->verify('commerce-download', $token))->not->toBeNull()
			->and($later->verify('commerce-download', $token))->toBeNull();
	});

	it('says none are left, and gives no address, when the downloads are used up', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['downloadMaxCount' => 1] + $config->commerce;
		$order            = ($this->order)();
		get(($this->path)(($this->links)($order)[0]['url']));

		$link = ($this->links)($order)[0];

		expect($link['remaining'])->toBe(0)
			->and($link['url'])->toBe('');
	});
});

describe('downloading', function (): void {
	beforeEach(function (): void {
		$this->placed = ($this->order)();
		$this->url    = ($this->path)(($this->links)($this->placed)[0]['url']);
	});

	it('hands over the file, as a download under its own name', function (): void {
		$res = get($this->url);

		expect($res->getStatusCode())->toBe(200)
			->and((string)$res->getBody())->toBe('%PDF-1.4 the guide')
			->and($res->getHeaderLine('Content-Disposition'))->toContain('attachment', 'guide.pdf')
			->and($res->getHeaderLine('Content-Type'))->toContain('pdf');
	});

	it('counts each download on the order\'s line', function (): void {
		get($this->url);
		get($this->url);

		expect(($this->line)($this->placed, 'guide')['downloads'])->toBe(['count' => 2])
			->and(($this->links)($this->placed)[0]['remaining'])->toBe(3);
	});

	it('stops at the limit, and says how to get help', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['downloadMaxCount' => 2] + $config->commerce;
		get($this->url);
		get($this->url);

		$res = get($this->url);

		expect($res->getStatusCode())->toBe(410)
			->and((string)$res->getBody())->toContain('downloaded as many times as', 'Order ' . $this->placed->number())
			->and(($this->line)($this->placed, 'guide')['downloads'])->toBe(['count' => 2]);
	});

	it('tells the listeners a file was downloaded', function (): void {
		$heard = [];
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::FILE_DOWNLOADED, function (array $payload) use (&$heard): void {
			$heard[] = [$payload['collection'], $payload['id'], $payload['property'], $payload['filename']];
		});

		get($this->url);

		expect($heard)->toBe([['products', 'guide', 'download', 'guide.pdf']]);
	});

	it('refuses a link that was made up, changed or has run out', function (string $token): void {
		$res = get('/commerce/download/' . $token);

		expect($res->getStatusCode())->toBe(404)
			->and((string)$res->getBody())->toContain('no longer valid');
	})->with([
		'made up'                  => ['not-a-token'],
		'another purpose\'s token' => [fn (): string => test()->c->get(SignedToken::class)->sign('something-else', ['o' => 'x', 'l' => 'y'], time() + 60)],
		'run out'                  => [fn (): string => test()->c->get(SignedToken::class)->sign('commerce-download', ['o' => test()->placed->id(), 'l' => 'x'], time() - 10)],
	]);

	it('refuses a link for a line the order does not have', function (): void {
		$token = $this->c->get(SignedToken::class)->sign('commerce-download', ['o' => $this->placed->id(), 'l' => 'nope'], time() + 60);

		expect(get('/commerce/download/' . $token)->getStatusCode())->toBe(404);
	});

	it('refuses once the money has all gone back', function (): void {
		$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $this->placed->id(), $this->placed->grandTotal(), 'evt_refund'));

		$res = get($this->url);

		expect($res->getStatusCode())->toBe(403)
			->and((string)$res->getBody())->not->toContain('%PDF');
	});

	it('says so when the store has taken the file away', function (): void {
		($this->asStore)(fn () => $this->c->get(FileRemover::class)->deleteFile('products', 'guide', 'download', 'guide.pdf'));

		$res = get($this->url);

		expect($res->getStatusCode())->toBe(404)
			->and((string)$res->getBody())->toContain('is not available');
	});

	it('gives the buyer the file the product has now, when the store replaced it', function (): void {
		($this->attach)('guide', 'guide-v2.pdf', '%PDF-1.4 second edition');

		$res = get($this->url);

		expect((string)$res->getBody())->toBe('%PDF-1.4 second edition')
			// A file field keeps the name it is downloaded under when its file is replaced.
			->and($res->getHeaderLine('Content-Disposition'))->toContain('guide.pdf')
			// The allowance is the line's, whatever the file is called now.
			->and(($this->links)($this->placed)[0]['remaining'])->toBe(4);
	});

	it('still works on a site whose Commerce add-on has lapsed: the goods were paid for', function (): void {
		$this->c->set(CommerceGate::class, new ClosedCommerceGate());

		expect(get($this->url)->getStatusCode())->toBe(200);
	});
});

/*
 * The file is the product, and it is protected the way any file is: by the
 * File Access Groups of its collection. The store installs its products
 * collection with the group `admin`, so a product's file is closed to the
 * public from the first upload. The buyer's signed link is not one of these
 * addresses and asks nothing of the buyer but the link.
 */
describe('the site\'s ordinary file addresses', function (): void {
	it('refuse a visitor the product\'s file', function (string $path): void {
		$res = get($path);

		expect($res->getStatusCode())->toBeIn([302, 401, 403])
			->and((string)$res->getBody())->not->toContain('%PDF');
	})->with([
		'/download/products/guide/download',
		'/stream/products/guide/download',
	]);

	it('refuse a customer who is signed in but is not in the collection\'s groups', function (): void {
		seedCommerceStaff($this->app);
		signInAs($this->app, 'writer', 'auth');

		expect(get('/download/products/guide/download')->getStatusCode())->toBeIn([401, 403]);
	});

	it('hand it to staff in the collection\'s groups, so the product form\'s own download works', function (): void {
		seedCommerceStaff($this->app);
		signInAs($this->app, 'boss', 'auth');
		$res = get('/download/products/guide/download');

		expect($res->getStatusCode())->toBe(200)
			->and((string)$res->getBody())->toBe('%PDF-1.4 the guide');
	});

	it('still hand a buyer the file through their own link', function (): void {
		$order = ($this->order)();

		expect((string)get(($this->path)(($this->links)($order)[0]['url']))->getBody())->toBe('%PDF-1.4 the guide');
	});
});

/*
 * A product's download need not be a file kept on the site. The product has
 * one Download field, and the type of that field says where the download is
 * kept: a file field is kept here, a url field is a link to a file kept
 * anywhere, and an extension can add a source for a field type of its own (S3
 * Uploads does). Whatever it is, the buyer is given the same signed link, and
 * each download is counted the same way; a download that is not kept here
 * ends with the buyer sent on to where it is.
 *
 * The manual is a $12.00 download whose Download is a link.
 */
describe('a download that is a link', function (): void {
	beforeEach(function (): void {
		$this->manualUrl = 'https://files.example.test/manuals/Manual%20v2.zip?edition=2';
		($this->downloadIs)('url');
		($this->asStore)(fn () => $this->c->get(ObjectSaver::class)->saveObject('downloads', [
			'id' => 'manual', 'title' => 'Manual', 'status' => 'active', 'type' => 'digital', 'price' => 12, 'requiresShipping' => false, 'download' => $this->manualUrl,
		]));
	});

	it('is delivered when the order is paid, under the name of the file it leads to', function (): void {
		$order = ($this->order)(['manual' => 1]);
		$links = ($this->links)($order);

		expect(($this->line)($order, 'manual')['fulfillmentStatus'])->toBe('fulfilled')
			->and($links)->toHaveCount(1)
			->and($links[0]['fileName'])->toBe('Manual v2.zip')
			// Nobody knows how big a file kept elsewhere is.
			->and($links[0]['size'])->toBe(0)
			->and($links[0]['url'])->toContain('/commerce/download/')
			->and($links[0]['url'])->not->toContain('files.example.test');
	});

	it('counts the download, then sends the buyer on to the link', function (): void {
		$order = ($this->order)(['manual' => 1]);
		$res   = get(($this->path)(($this->links)($order)[0]['url']));

		expect($res->getStatusCode())->toBe(302)
			->and($res->getHeaderLine('Location'))->toBe($this->manualUrl)
			->and($res->getHeaderLine('Cache-Control'))->toContain('no-store')
			->and($res->getHeaderLine('Referrer-Policy'))->toBe('no-referrer')
			->and(($this->line)($order, 'manual')['downloads'])->toBe(['count' => 1]);
	});

	it('stops at the limit, as a file does', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['downloadMaxCount' => 1] + $config->commerce;
		$order            = ($this->order)(['manual' => 1]);
		$url              = ($this->path)(($this->links)($order)[0]['url']);
		get($url);

		expect(get($url)->getStatusCode())->toBe(410);
	});

	it('tells the site which field the download came from', function (): void {
		$heard = [];
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::FILE_DOWNLOADED, function (array $payload) use (&$heard): void {
			$heard[] = [$payload['collection'], $payload['id'], $payload['property'], $payload['filename']];
		});
		$order = ($this->order)(['manual' => 1]);

		get(($this->path)(($this->links)($order)[0]['url']));

		expect($heard)->toBe([['downloads', 'manual', 'download', 'Manual v2.zip']]);
	});

	it('shows no size for it on the order page', function (): void {
		$order = ($this->order)(['manual' => 1]);
		$html  = html_entity_decode((string)get("/commerce/order/{$order->id()}/status?t=" . rawurlencode($this->c->get(OrderAccess::class)->token($order)))->getBody());

		expect($html)->toContain('Manual v2.zip', '5 downloads left')
			->and(preg_match('/<small>\s*,/', $html))->toBe(0)
			->and($html)->not->toContain('0 B', '0 bytes');
	});

	it('names a link with no file in it by its host', function (): void {
		expect((new UrlDownloadSource())->find('products', ['download' => 'https://learn.example.test/'])?->name)->toBe('learn.example.test');
	});

	it('is no download unless it is a web address', function (mixed $value): void {
		$source = new UrlDownloadSource();

		expect($source->find('products', ['download' => $value]))->toBeNull()
			->and($source->deliver('products', ['download' => $value]))->toBeNull();
	})->with([
		'a script'             => ['javascript:alert(1)'],
		'another kind of link' => ['ftp://files.example.test/a.zip'],
		'no scheme'            => ['//files.example.test/a.zip'],
		'not a link'           => ['the manual'],
		'nothing'              => [''],
		'not text'             => [['https://files.example.test/a.zip']],
	]);
});

describe('which source delivers a download', function (): void {
	it('is the one for the type of the Download field: a schema that makes it a link delivers by redirect', function (): void {
		($this->downloadIs)('url');
		$product = ['id' => 'manual', 'download' => 'https://files.example.test/manual.pdf'];

		[$source, $found] = $this->c->get(DownloadSources::class)->for('downloads', $product);
		$delivery         = $source->deliver('products', $product);

		expect($source)->toBeInstanceOf(UrlDownloadSource::class)
			->and($found->name)->toBe('manual.pdf')
			->and($delivery?->url)->toBe('https://files.example.test/manual.pdf');
	});

	it('does not ask the file source when the schema says link', function (): void {
		($this->downloadIs)('url');

		// Even a product that has a file stored under the same name.
		[$source] = $this->c->get(DownloadSources::class)->for('downloads', ['id' => 'guide', 'download' => 'https://files.example.test/guide.pdf']);

		expect($source)->toBeInstanceOf(UrlDownloadSource::class);
	});

	it('is the file source on the store\'s own product schema, which asks for nothing more', function (): void {
		[$source, $found] = $this->c->get(DownloadSources::class)->for('products', ['id' => 'guide']);

		expect($source)->toBeInstanceOf(FileDownloadSource::class)
			->and($found->name)->toBe('guide.pdf');
	});

	it('offers nothing, and logs once, for a field type no source handles; the order still completes', function (): void {
		($this->downloadIs)('text');
		[$sources, $log] = ($this->logged)();

		expect($sources->for('downloads', ['id' => 'manual', 'download' => 'https://files.example.test/manual.pdf']))->toBeNull()
			->and($log->getRecords())->toHaveCount(1)
			->and($log->hasErrorThatContains('type text'))->toBeTrue();

		($this->asStore)(fn () => $this->c->get(ObjectSaver::class)->saveObject('downloads', [
			'id' => 'manual', 'title' => 'Manual', 'status' => 'active', 'type' => 'digital', 'price' => 12, 'requiresShipping' => false, 'download' => 'https://files.example.test/manual.pdf',
		]));
		$order = ($this->order)(['manual' => 1]);

		expect(($this->line)($order, 'manual')['fulfillmentStatus'])->toBe('unfulfilled')
			->and(($this->links)($order))->toBe([])
			->and(($this->fresh)($order)->paymentStatus())->toBe('paid');
	});

	it('offers nothing, and logs once, when the products collection cannot be read; the order page does not break', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['collections' => ['products' => 'no-such-collection']] + $config->commerce;
		[$sources, $log] = ($this->logged)();

		expect($sources->for('no-such-collection', ['id' => 'guide']))->toBeNull()
			->and($log->getRecords())->toHaveCount(1)
			->and($log->hasErrorThatContains('no-such-collection'))->toBeTrue();
	});

	it('has no download, and says nothing, when the product schema has no digital part', function (): void {
		($this->downloadIs)('file', ['base']);
		[$sources, $log] = ($this->logged)();

		expect($sources->for('downloads', ['id' => 'guide']))->toBeNull()
			->and($log->getRecords())->toBe([]);
	});
});

describe('a source an extension adds', function (): void {
	beforeEach(function (): void {
		$this->fake = new FakeDownloadSource(['course' => 'https://cdn.example.test/kept/course.zip']);
		$this->c->get(DownloadSources::class)->add($this->fake);
	});

	it('delivers a product it has something for', function (): void {
		$order = ($this->order)(['course' => 1]);
		$links = ($this->links)($order);

		expect(($this->line)($order, 'course')['fulfillmentStatus'])->toBe('fulfilled')
			->and([$links[0]['fileName'], $links[0]['size']])->toBe(['course.zip', 4096])
			->and(get(($this->path)($links[0]['url']))->getHeaderLine('Location'))->toBe('https://cdn.example.test/kept/course.zip');
	});

	it('takes the place of the store\'s own source for the same field type', function (): void {
		$this->fake->links['guide'] = 'https://cdn.example.test/kept/guide.zip';

		expect(($this->links)(($this->order)())[0]['fileName'])->toBe('guide.zip');
	});

	it('is left alone by a schema whose Download is another type', function (): void {
		($this->downloadIs)('url');
		$this->fake->links['manual'] = 'https://cdn.example.test/kept/manual-kept.zip';

		[$source] = $this->c->get(DownloadSources::class)->for('downloads', ['id' => 'manual', 'download' => 'https://files.example.test/manual.zip']);

		expect($source)->toBeInstanceOf(UrlDownloadSource::class);
	});

	it('is passed over when it fails, and logged, and nothing else delivers that type', function (): void {
		$this->fake->broken = true;

		$order = ($this->order)();

		expect(($this->links)($order))->toBe([])
			->and(($this->line)($order, 'guide')['fulfillmentStatus'])->toBe('unfulfilled');

		[$sources, $log] = ($this->logged)();
		$sources->add($this->fake);

		expect($sources->for('products', ['id' => 'guide']))->toBeNull()
			->and($log->getRecords())->toHaveCount(1)
			->and($log->hasErrorThatContains('A download source failed'))->toBeTrue();
	});
});

describe('where the buyer finds them', function (): void {
	beforeEach(function (): void {
		$this->placed = ($this->order)(['guide' => 1, 'mug' => 1]);
		$this->token  = $this->c->get(OrderAccess::class)->token($this->placed);
	});

	it('shows them on the order page', function (): void {
		$html = (string)get("/commerce/order/{$this->placed->id()}/status?t=" . rawurlencode($this->token))->getBody();

		expect(html_entity_decode($html))->toContain('Your downloads', 'guide.pdf', '/commerce/download/', '5 downloads left');
	});

	it('shows none on the order page of an order still waiting to be paid', function (): void {
		$pending = ($this->order)(['guide' => 1], false);
		$token   = $this->c->get(OrderAccess::class)->token($pending);

		expect((string)get("/commerce/order/{$pending->id()}/status?t=" . rawurlencode($token))->getBody())->not->toContain('Your downloads', '/commerce/download/');
	});

	it('gives them to a template with the order', function (): void {
		$out = renderTwig('{% set order = cms.commerce.order(id, token) %}{% for d in cms.commerce.downloads(order) %}{{ d.fileName }}|{{ d.remaining }}|{{ d.url }}{% endfor %}', ['id' => $this->placed->id(), 'token' => $this->token]);

		expect($out)->toContain('guide.pdf|5|', '/commerce/download/');
	});

	// A page that built an "order" from what a visitor sent must get nothing.
	it('gives none to a template for an order it was not handed', function (): void {
		expect(renderTwig('{{ cms.commerce.downloads({id: id})|length }}', ['id' => $this->placed->id()]))->toBe('0')
			->and(renderTwig('{{ cms.commerce.downloads({id: id, accessToken: "guess"})|length }}', ['id' => $this->placed->id()]))->toBe('0');
	});

	// A link in an old receipt runs out. The order's own page makes new ones,
	// so the receipt says how to get back to it.
	it('puts the way back to the order in the receipt', function (): void {
		$mailer = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/commerce/mailers/commerce-receipt.json'), true);
		$data   = ['data' => $this->placed->toArray()];
		$html   = html_entity_decode(renderTwig((string)$mailer['bodyHtml'], $data));

		expect($html)->toContain("/commerce/return/{$this->placed->id()}?t=", 'View your order')
			->and(renderTwig((string)$mailer['bodyText'], $data))->toContain("/commerce/return/{$this->placed->id()}?t=");
	});

	it('gives a template the order\'s own address only for an order it was handed whole', function (): void {
		expect(renderTwig('{{ cms.commerce.orderUrl(order) }}', ['order' => $this->placed->toArray()]))->toContain("/commerce/return/{$this->placed->id()}?t=")
			->and(renderTwig('{{ cms.commerce.orderUrl({id: id}) }}', ['id' => $this->placed->id()]))->toBe('')
			->and(renderTwig('{{ cms.commerce.orderUrl({id: id, accessToken: "guess"}) }}', ['id' => $this->placed->id()]))->toBe('');
	});

	it('puts them in the receipt', function (): void {
		$mailer = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/commerce/mailers/commerce-receipt.json'), true);
		$data   = ['data' => $this->placed->toArray()];

		expect(html_entity_decode(renderTwig((string)$mailer['bodyHtml'], $data)))->toContain('Your downloads', 'guide.pdf', '/commerce/download/', '72 hours')
			->and(renderTwig((string)$mailer['bodyText'], $data))->toContain('guide.pdf: http', '/commerce/download/');
	});
});

test('staff see on the order how much of the buyer\'s allowance is used', function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	seedCommerceStaff($this->app);
	$order = ($this->order)();
	get(($this->path)(($this->links)($order)[0]['url']));
	signInAs($this->app, 'keeper', 'auth');

	expect((string)get("/admin/commerce/orders/{$order->id()}")->getBody())->toContain('Delivered as a download', 'Downloaded 1 of 5 times');
});

describe('a paid download open to the public', function (): void {
	// The products collection `downloads`, on the given download field, with these File Access Groups.
	beforeEach(function (): void {
		$this->open = function (string $field, array $groups): void {
			commerceDownloadIs($this->c, $field);
			$this->c->get(CollectionSaver::class)->updateCollection('downloads', ['id' => 'downloads', 'name' => 'Downloads', 'schema' => 'download-is-' . $field, 'groups' => $groups]);
		};
	});

	test('the bundled products collection is protected', function (): void {
		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBeNull();
	});

	test('a products collection with a file download and no File Access Groups is reported', function (): void {
		($this->open)('file', []);

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBe('downloads');
	});

	test('giving it a group clears the report', function (): void {
		($this->open)('file', ['admin']);

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBeNull();
	});

	test('a products collection whose groups include default is reported as open to anyone who signs in', function (array $groups): void {
		($this->open)('file', $groups);
		$open = $this->c->get(DownloadProtection::class)->openDownloads();

		expect($open?->collection)->toBe('downloads')
			->and($open?->anySignedIn)->toBeTrue()
			->and($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBe('downloads');
	})->with(['only default' => [['default']], 'admin and default' => [['admin', 'default']]]);

	test('with no groups the report says the files are open to the public', function (): void {
		($this->open)('file', []);

		expect($this->c->get(DownloadProtection::class)->openDownloads()?->anySignedIn)->toBeFalse();
	});

	test('a group that is not default is not reported', function (): void {
		($this->open)('file', ['admin']);

		expect($this->c->get(DownloadProtection::class)->openDownloads())->toBeNull();
	});

	test('a download kept in S3 with no groups is reported too', function (): void {
		($this->open)('s3file', []);

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBe('downloads');
	});

	test('a download that is a link is never reported', function (): void {
		($this->open)('url', []);

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBeNull();
	});

	test('a product schema with no download is never reported', function (): void {
		commerceDownloadIs($this->c, 'file', ['base']);
		$this->c->get(CollectionSaver::class)->updateCollection('downloads', ['id' => 'downloads', 'name' => 'Downloads', 'schema' => 'download-is-file', 'groups' => []]);

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBeNull();
	});

	test('a products collection that does not exist is not reported and does not throw', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['collections' => ['products' => 'nowhere']] + $config->commerce;

		expect($this->c->get(DownloadProtection::class)->unprotectedCollection())->toBeNull();
	});
});
