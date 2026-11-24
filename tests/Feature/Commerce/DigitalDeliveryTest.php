<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Download\DownloadLinks;
use TotalCMS\Domain\Commerce\Download\ProductDownload;
use Tests\Fakes\ClosedCommerceGate;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\FileRemover;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Security\SignedToken;
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
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	$this->asStore = fn (Closure $work) => $this->c->get(ActorContext::class)->runAs(Actor::system(), $work);
	($this->asStore)(function (): void {
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
	($this->attach)('guide');

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
		'made up' => ['not-a-token'],
		'another purpose\'s token' => [fn (): string => test()->c->get(SignedToken::class)->sign('something-else', ['o' => 'x', 'l' => 'y'], time() + 60)],
		'run out' => [fn (): string => test()->c->get(SignedToken::class)->sign('commerce-download', ['o' => test()->placed->id(), 'l' => 'x'], time() - 10)],
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
 * The file is the product. The site's ordinary addresses for a file must
 * never hand it over, to anyone.
 */
describe('the file is served nowhere else', function (): void {
	it('is not found at the ordinary download and stream addresses', function (string $path): void {
		expect(get($path)->getStatusCode())->toBeIn([403, 404])
			->and((string)get($path)->getBody())->not->toContain('%PDF');
	})->with([
		'/download/products/guide/download',
		'/stream/products/guide/download',
		'/download/products/guide/download/guide.pdf',
		'/stream/products/guide/download/guide.pdf',
		'/download/upload/products/guide/download/guide.pdf',
		'/stream/upload/products/guide/download/guide.pdf',
	]);

	it('knows a product\'s download from any other file', function (string $collection, string $property, bool $guarded): void {
		expect($this->c->get(ProductDownload::class)->guards($collection, $property))->toBe($guarded);
	})->with([
		'the products collection\'s download' => ['products', 'download', true],
		'another field of a product'          => ['products', 'images', false],
		'a field called download elsewhere'   => ['orders', 'download', false],
		'a collection that does not exist'    => ['nope', 'download', false],
	]);
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
