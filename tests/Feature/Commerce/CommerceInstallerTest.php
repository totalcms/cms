<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TotalCMS\CLI\Command\Commerce\CommerceInstallCommand;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Data\InstallReport;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Commerce\Service\LicenseCommerceGate;
use TotalCMS\Domain\Mailer\Service\EmailSender;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Mailer\Service\MailerFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;
use TotalCMS\TotalCMS;

/*
 * `tcms commerce:install` gives a site the collections Commerce stores its
 * records in and the two emails it sends. It can be run any number of times,
 * and it never changes something that is already there.
 */

const COMMERCE_COLLECTIONS = [
	'products'      => 'commerce-product',
	'orders'        => 'commerce-order',
	'transactions'  => 'commerce-transaction',
	'carts'         => 'commerce-cart',
	'subscriptions' => 'commerce-subscription',
	'discounts'     => 'commerce-discount',
];

const COMMERCE_MAILERS = ['mailer/commerce-receipt', 'mailer/commerce-payment-failed'];

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
});

function commerceInstall(): InstallReport
{
	return test()->c->get(CommerceInstaller::class)->install();
}

test('a fresh install creates the six collections, each on its commerce schema', function (): void {
	$report = commerceInstall();

	expect($report->errors)->toBe([])
		->and($report->existing)->toBe([])
		->and($report->created)->toBe([...array_keys(COMMERCE_COLLECTIONS), ...COMMERCE_MAILERS])
		->and($report->ok())->toBeTrue();

	foreach (COMMERCE_COLLECTIONS as $id => $schema) {
		$collection = $this->c->get(CollectionFetcher::class)->fetchCollection($id);

		expect($collection)->not->toBeNull("{$id} was not created")
			->and($collection->schema)->toBe($schema)
			->and($collection->category)->toBe('Commerce');
	}
});

test('nothing the engine writes, and no discount code, is open to the public', function (string $id): void {
	commerceInstall();

	expect($this->c->get(CollectionFetcher::class)->fetchCollection($id)->publicOperations)->toBe([]);
})->with(['orders', 'transactions', 'carts', 'subscriptions', 'discounts']);

test('a product\'s files are closed to the public from the start: the products collection has a file access group', function (): void {
	commerceInstall();

	expect($this->c->get(CollectionFetcher::class)->fetchCollection('products')->groups)->toBe(['admin'])
		// The rest hold no files to protect.
		->and($this->c->get(CollectionFetcher::class)->fetchCollection('orders')->groups)->toBe([]);
});

test('the collections are labelled for the people who use them', function (): void {
	commerceInstall();
	$name = fn (string $id): string => $this->c->get(CollectionFetcher::class)->fetchCollection($id)->name;

	expect($name('products'))->toBe('Products')
		->and($name('orders'))->toBe('Orders')
		->and($name('transactions'))->toBe('Transactions')
		->and($name('carts'))->toBe('Carts')
		->and($name('subscriptions'))->toBe('Subscriptions')
		->and($name('discounts'))->toBe('Discount codes');
});

test('a second run creates nothing and reports everything as already there', function (): void {
	commerceInstall();
	$report = commerceInstall();

	expect($report->created)->toBe([])
		->and($report->errors)->toBe([])
		->and($report->existing)->toBe([...array_keys(COMMERCE_COLLECTIONS), ...COMMERCE_MAILERS]);
});

test('a collection of that name on another schema is refused and left alone', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'orders', 'name' => 'Work Orders', 'schema' => 'blog']);

	$report = commerceInstall();
	$orders = $this->c->get(CollectionFetcher::class)->fetchCollection('orders');

	expect($report->errors)->toBe(['Collection "orders" exists with schema "blog"; set commerce.collections.orders to another id.'])
		->and($report->ok())->toBeFalse()
		->and($report->created)->not->toContain('orders')
		->and($orders->schema)->toBe('blog')
		->and($orders->name)->toBe('Work Orders')
		// The rest of the install still happens: one clash does not strand the others.
		->and($report->created)->toContain('products', 'transactions', 'carts', 'subscriptions', 'discounts');
});

test('collection ids come from the settings', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['collections' => ['orders' => 'shop-orders'] + $config->commerce['collections']] + $config->commerce;

	$report = commerceInstall();

	expect($report->created)->toContain('shop-orders')
		->and($report->created)->not->toContain('orders')
		->and($this->c->get(CollectionFetcher::class)->fetchCollection('shop-orders')->schema)->toBe('commerce-order')
		->and($this->c->get(CollectionFetcher::class)->collectionExists('orders'))->toBeFalse();
});

test('the receipt email is installed, addressed to the buyer', function (): void {
	commerceInstall();
	$mailer = $this->c->get(MailerFetcher::class)->fetchMailer('commerce-receipt')->toArray();

	expect($mailer['subject'])->toBe('Your order {{ data.number }} from {{ cms.commerce.storeName() }}')
		->and($mailer['fromName'])->toBe('{{ cms.commerce.storeName() }}')
		// Left empty for the owner to fill in under Mailer, as on any other email.
		->and($mailer['replyTo'] ?? '')->toBe('')
		->and($mailer['to'])->toBe('{{ data.email }}')
		->and($mailer['bodyHtml'])->toContain('|money(data.currency)', 'cms.commerce.downloads(data)', "data.kind == 'renewal'", 'data.discountCode')
		->and($mailer['bodyText'])->toContain('|money(data.currency)');
});

// The mailer hands a template its record as `data`. A receipt that looked for
// the order under any other name was addressed to nobody, and failed to send.
test('a receipt sent through the mailer is addressed to the buyer and shows the order', function (): void {
	commerceInstall();
	$sent   = [];
	$sender = $this->createMock(EmailSender::class);
	$sender->method('send')->willReturnCallback(function (array $email) use (&$sent): OperationResult {
		$sent[] = $email;

		return OperationResult::success('sent');
	});
	$this->c->set(EmailSender::class, $sender);
	$config           = $this->c->get(Config::class);
	$config->siteName = 'Larkspur & Co';

	$result = $this->c->get(EmailService::class)->sendEmail('commerce-receipt', [
		'id'            => 'order-1',
		'number'        => 1001,
		'kind'          => 'checkout',
		'email'         => 'buyer@example.com',
		'currency'      => 'USD',
		'lines'         => ['l1' => ['title' => 'Kiambu <AA>', 'qty' => 2, 'lineTotal' => 4400]],
		'subtotal'      => 4400,
		'discountCode'  => 'WELCOME10',
		'discountTotal' => 440,
		'shippingTotal' => 500,
		'taxTotal'      => 0,
		'grandTotal'    => 4460,
		'paidAt'        => '2026-11-23T11:51:27-08:00',
	]);

	expect($result->success)->toBeTrue()
		->and($sent)->toHaveCount(1)
		->and($sent[0]['to'])->toBe('buyer@example.com')
		->and($sent[0]['subject'])->toBe('Your order 1001 from Larkspur & Co')
		// The receipt comes from the site's name. Who a reply goes to is the
		// template's own Reply-To, empty until the owner sets it.
		->and($sent[0]['fromName'])->toBe('Larkspur & Co')
		->and($sent[0]['replyTo'])->toBe('')
		->and($sent[0]['bodyHtml'])->toContain('Larkspur &amp; Co has received your payment', 'dated November 23, 2026', 'reply to this email')
		->not->toContain('mailto:')
		->and($sent[0]['bodyText'])->toContain('Larkspur & Co has received your payment', 'dated November 23, 2026', 'reply to this email')
		->and($sent[0]['bodyHtml'])->toContain('Thank you for your order', '$44.00', 'Discount (WELCOME10)', '-$4.40', '$5.00', '$44.60')
		// The mailer writes the HTML back out through libxml, and which way the
		// multiplication sign comes out depends on its version: `&times;` from
		// 2.9, the character itself from newer ones. A mail reader shows the same.
		->toMatch('/Kiambu &lt;AA&gt; (?:&times;|×) 2/u')
		->not->toContain('{{', '{%', 'Tax', 'Your downloads')
		->and($sent[0]['bodyText'])->toContain('Kiambu <AA> x 2: $44.00', 'Total: $44.60')
		->not->toContain('{{', '{%');
});

test('the failed payment email is installed, with the link to fix the payment', function (): void {
	commerceInstall();
	$mailer = $this->c->get(MailerFetcher::class)->fetchMailer('commerce-payment-failed')->toArray();

	expect($mailer['subject'])->toBe('Your payment for {{ data.lines|first.title }} did not go through')
		->and($mailer['to'])->toBe('{{ data.email }}')
		->and($mailer['bodyHtml'])->toContain('cms.commerce.subscriptionUrl(data)')
		->and($mailer['bodyText'])->toContain('cms.commerce.subscriptionUrl(data)');
});

test('a receipt with no date to show still reads properly', function (): void {
	commerceInstall();
	$sent   = [];
	$sender = $this->createMock(EmailSender::class);
	$sender->method('send')->willReturnCallback(function (array $email) use (&$sent): OperationResult {
		$sent[] = $email;

		return OperationResult::success('sent');
	});
	$this->c->set(EmailSender::class, $sender);

	$this->c->get(EmailService::class)->sendEmail('commerce-receipt', ['number' => 1002, 'email' => 'buyer@example.com', 'currency' => 'USD', 'lines' => [], 'grandTotal' => 0]);

	expect($sent[0]['fromName'])->not->toBe('')
		->and($sent[0]['bodyHtml'])->toContain('reply to this email')->not->toContain('dated', '{{');
});

test('the failed payment email comes from the store too', function (): void {
	commerceInstall();
	$mailer = $this->c->get(MailerFetcher::class)->fetchMailer('commerce-payment-failed')->toArray();

	expect($mailer['fromName'])->toBe('{{ cms.commerce.storeName() }}')
		->and($mailer['replyTo'] ?? '')->toBe('');
});

test('everything a template prints from an order is escaped', function (string $id): void {
	// Twig does not escape for us here, and a product title or a buyer's
	// address is whatever someone typed.
	commerceInstall();
	$html = $this->c->get(MailerFetcher::class)->fetchMailer($id)->toArray()['bodyHtml'];

	preg_match_all('/\{\{(.*?)\}\}/s', $html, $prints);
	foreach ($prints[1] as $expression) {
		$safe = str_contains($expression, '|e') || str_contains($expression, '|money(') || str_contains($expression, '|url_encode');
		expect($safe)->toBeTrue("unescaped in {$id}: {{{$expression}}}");
	}
})->with(['commerce-receipt', 'commerce-payment-failed']);

test('an email the operator has edited is never overwritten', function (): void {
	commerceInstall();
	foreach (['commerce-receipt' => 'Thanks from the shop', 'commerce-payment-failed' => 'Card trouble'] as $id => $subject) {
		$mailer = $this->c->get(ObjectFetcher::class)->fetchObject('mailer', $id)->toArray();
		$this->c->get(ObjectUpdater::class)->updateObject('mailer', $id, ['subject' => $subject] + $mailer);
	}

	commerceInstall();

	expect($this->c->get(MailerFetcher::class)->fetchMailer('commerce-receipt')->toArray()['subject'])->toBe('Thanks from the shop')
		->and($this->c->get(MailerFetcher::class)->fetchMailer('commerce-payment-failed')->toArray()['subject'])->toBe('Card trouble');
});

describe('tcms commerce:install', function (): void {
	beforeEach(function (): void {
		// The command asks the gate when it runs; the test license has no add-on.
		enableCommerce($this->app);

		$totalcms = $this->createMock(TotalCMS::class);
		$totalcms->method('container')->willReturn($this->c);

		$app = new Application();
		$app->addCommand(new CommerceInstallCommand($totalcms));
		$this->command = new CommandTester($app->find('commerce:install'));
	});

	test('prints the report as JSON', function (): void {
		$code = $this->command->execute(['--json' => true]);

		expect($code)->toBe(0)
			->and(json_decode($this->command->getDisplay(), true))->toBe([
				'created'  => [...array_keys(COMMERCE_COLLECTIONS), ...COMMERCE_MAILERS],
				'existing' => [],
				'errors'   => [],
			]);
	});

	test('says in plain words what it created and what was already there', function (): void {
		$this->command->execute([]);
		expect($this->command->getDisplay())->toContain('Created', 'products', 'mailer/commerce-receipt');

		$this->command->execute([]);
		expect($this->command->getDisplay())->toContain('Already there', 'products')
			->and($this->command->getDisplay())->not->toContain('Created');
	});

	test('fails, and says why, when a collection is in the way', function (): void {
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'carts', 'name' => 'Go-karts', 'schema' => 'blog']);

		$code = $this->command->execute([]);

		expect($code)->toBe(1)
			->and($this->command->getDisplay())->toContain('Collection "carts" exists with schema "blog"; set commerce.collections.carts to another id.');
	});

	test('creates nothing on a site without the Commerce add-on, and says how to get it', function (): void {
		$this->c->set(CommerceGate::class, $this->c->get(LicenseCommerceGate::class));

		$code = $this->command->execute([]);

		expect($code)->toBe(1)
			->and($this->command->getDisplay())->toContain('Commerce requires Total CMS Pro and the Commerce add-on.', 'https://totalcms.co/commerce')
			->and($this->c->get(CollectionFetcher::class)->fetchCollection('products'))->toBeNull()
			->and($this->c->get(CollectionFetcher::class)->fetchCollection('orders'))->toBeNull();
	});

	test('the refusal is JSON when JSON is asked for', function (): void {
		$this->c->set(CommerceGate::class, $this->c->get(LicenseCommerceGate::class));

		$code = $this->command->execute(['--json' => true]);

		expect($code)->toBe(1)
			->and(json_decode($this->command->getDisplay(), true)['error'] ?? '')->toContain('Commerce requires Total CMS Pro');
	});

	test('is registered with the tcms command line', function (): void {
		expect(file_get_contents(dirname(__DIR__, 3) . '/src/CLI/CliApplication.php'))->toContain('Command\Commerce\CommerceInstallCommand($totalcms)');
	});
});
