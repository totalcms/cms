<?php

declare(strict_types=1);

use DI\Container;
use Odan\Session\PhpSession;
use Tests\Fakes\RecordingHttpClient;
use TotalCMS\Bundled\S3Uploads\Field\S3Field;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Download\DownloadLinks;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\postJson;

/*
 * A product's download kept in a bucket. With S3 Uploads on, the product
 * form has a Download from S3 field, and the extension is one of the places
 * the store looks for a product's download. The buyer is given the store's
 * own signed link, as for any download; behind it the download is counted
 * and the buyer is sent to a signed link to the bucket.
 *
 * The real extension, the real store and the real routes. Only the bucket
 * is a fake. The video is a $30.00 download with a file in the bucket.
 */

beforeEach(function (): void {
	$this->fieldTypes = snapshotExtensionFieldTypes();
	bootCommerceStore(true, function (): void {
		$system = cmsDataDir() . '.system';
		is_dir($system . '/extension-settings/totalcms') || mkdir($system . '/extension-settings/totalcms', 0755, true);
		file_put_contents($system . '/extensions.json', json_encode(['totalcms/s3-uploads' => ['enabled' => true]]));
		file_put_contents($system . '/extension-settings/totalcms/s3-uploads.json', json_encode([
			'region' => 'us-west-2', 'bucket' => 'my-bucket', 'accessKey' => 'AKIDEXAMPLE', 'secretKey' => 'secret', 'prefix' => 'site/',
		]));
	});
	$this->c = $this->app->getContainer();
	assert($this->c instanceof Container);
	// The bucket. The store's own first products were saved while the site
	// booted, and that built the extension's bucket client on the offline
	// HTTP client every test starts with: build it again on the fake.
	$this->bucket = new RecordingHttpClient();
	$this->c->set(HttpClientInterface::class, $this->bucket);
	$this->c->set(S3Client::class, $this->c->make(S3Client::class));
	$this->orders = $this->c->get(OrderRepository::class);

	$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectSaver::class)->saveObject('products', [
		'id' => 'video', 'title' => 'Video', 'status' => 'active', 'type' => 'digital', 'price' => 30, 'requiresShipping' => false,
	]));

	// Staff upload the file the way the field does: straight to the bucket, then the site records it.
	$this->upload = function (): void {
		signInAs($this->app, 'admin-user-test-com');
		$presign = postJson('/admin/ext/totalcms/s3-uploads/presign', [
			'collection' => 'products', 'id' => 'video', 'property' => 'downloadS3', 'filename' => 'The Video.mp4', 'size' => 900000, 'mime' => 'video/mp4',
		]);
		expect($presign->getStatusCode())->toBe(200);
		$this->bucket->push(new HttpResponse(200, '', ['content-length' => '900000', 'content-type' => 'video/mp4', 'etag' => '"abc"']));
		$commit = postJson('/admin/ext/totalcms/s3-uploads/commit', ['token' => json_decode((string)$presign->getBody(), true)['token']]);
		expect($commit->getStatusCode())->toBe(200);
		// The buyer is nobody the site knows. The session is closed between requests: open it to sign out.
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
		$session->delete(SessionKeys::AUTH_USER);
		$session->delete(SessionKeys::AUTH_COLLECTION);
	};

	$this->order = function (): OrderData {
		$order = $this->orders->create(commerceDraft(['video' => 1]), 'fake');
		$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $order->grandTotal(), 'evt_' . $order->number()));

		return $this->orders->find($order->id());
	};
	$this->links = fn (OrderData $order): array => $this->c->get(DownloadLinks::class)->for($this->orders->find($order->id()));
});

// The extension keeps its field types, and its fields' notice, in
// process-wide statics. Put both back, or they leak into the next test file.
afterEach(function (): void {
	restoreExtensionFieldTypes($this->fieldTypes);
	if (class_exists(S3Field::class, false)) {
		S3Field::configure(null);
	}
});

test('the product form has a Download from S3 field when S3 Uploads is on', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	expect($this->c->get(TotalFormFactory::class)->builder('products')->autoBuild())->toContain('Download from S3', '--grid-area: downloadS3;');
});

test('a product with nothing in the bucket has nothing to deliver', function (): void {
	$order = ($this->order)();

	expect(array_values($order->lines())[0]['fulfillmentStatus'])->toBe('unfulfilled')
		->and(($this->links)($order))->toBe([]);
});

test('a paid order delivers the file in the bucket, under its own name and size', function (): void {
	($this->upload)();
	$order = ($this->order)();
	$links = ($this->links)($order);

	expect(array_values($order->lines())[0]['fulfillmentStatus'])->toBe('fulfilled')
		->and($links)->toHaveCount(1)
		->and([$links[0]['fileName'], $links[0]['size']])->toBe(['The Video.mp4', 900000])
		->and($links[0]['url'])->toContain('/commerce/download/')
		// The buyer is never shown the bucket.
		->and($links[0]['url'])->not->toContain('my-bucket', 'amazonaws');
});

test('downloading counts it and sends the buyer to a signed link to the bucket that saves the file under its name', function (): void {
	($this->upload)();
	$order = ($this->order)();
	$res   = get((string)parse_url(($this->links)($order)[0]['url'], PHP_URL_PATH));
	$to    = $res->getHeaderLine('Location');

	expect($res->getStatusCode())->toBe(302)
		->and($to)->toContain('my-bucket', 'site/products/video/downloadS3/', 'X-Amz-Signature=', 'response-content-disposition=')
		->and(rawurldecode($to))->toContain('The Video.mp4')
		->and($res->getHeaderLine('Cache-Control'))->toContain('no-store')
		->and(array_values($this->orders->find($order->id())->lines())[0]['downloads'])->toBe(['count' => 1]);
});

test('the extension\'s own addresses for the file refuse a visitor: the products collection has file access groups', function (string $path): void {
	($this->upload)();
	$res = get($path);

	expect($res->getStatusCode())->toBeIn([302, 401, 403])
		->and($res->getHeaderLine('Location'))->not->toContain('my-bucket');
})->with([
	'/api/ext/totalcms/s3-uploads/download/products/video/downloadS3',
	'/api/ext/totalcms/s3-uploads/stream/products/video/downloadS3',
]);
