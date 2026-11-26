---
title: "Commerce Events and Extensions"
description: "Act on a paid order from your own code, add a payment provider, set prices that depend on the buyer, and refuse a checkout, with Total CMS extensions."
since: "3.7.0"
audience: advanced
related:
  - extensions/events
  - extensions/extension-points
  - commerce/orders
---

# Commerce Events and Extensions

Commerce tells your code what happens to an order, and lets an [extension](docs/extensions/overview) change how the store prices, checks and takes payment.

## Order events

| Event | When |
|---|---|
| `order.created` | A buyer started a checkout. Nothing is paid yet. |
| `order.paid` | The payment provider confirmed the payment. |
| `order.fulfilled` | Every item that needs delivering has been delivered. Once per order. |
| `order.refunded` | The provider confirmed a refund, in part or in full. |
| `order.disputed` | The buyer disputed the payment. |

```php
$context->addEventListener('order.paid', function (array $payload): void {
    // $payload['orderId'], $payload['email'], $payload['lines'] ...
});
```

### What a listener is given

| Key | Type | Description |
|---|---|---|
| `orderId` | `string` | The order's ID |
| `orderNumber` | `int` | The number buyers see |
| `collection` | `string` | The collection orders are kept in |
| `kind` | `string` | `checkout`, or `renewal` for a later payment on a subscription |
| `subscriptionId` | `string` | The subscription this order belongs to, or `''` |
| `paymentStatus` | `string` | As it is now |
| `fulfillmentStatus` | `string` | As it is now |
| `currency` | `string` | Three-letter code |
| `grandTotal` | `int` | The order's total, in minor units (cents) |
| `refundedTotal` | `int` | What has gone back so far |
| `amount` | `int` | What this event moved: the refund for `order.refunded`, the total otherwise |
| `discountCode` | `string` | The code used, or `''` |
| `discountTotal` | `int` | What it took off |
| `email` | `string` | The buyer's email |
| `customer` | `?array` | `{collection, id}` for a signed-in customer, `null` for a guest |
| `provider` | `string` | The payment provider's ID |
| `providerPaymentRef` | `string` | The payment's ID at the provider |
| `transactionId` | `string` | The line in the order's payments this event belongs to |
| `lines` | `array` | The order's lines, by line ID: `productId`, `variantId`, `sku`, `title`, `type`, `qty`, `unitPrice`, `lineTotal`, `discountTotal`, `billing` (`once` or `recurring`), `accessGroup`, `meta`, `metadata`, `fulfillmentStatus` |
| `attributes` | `array` | What the buyer gave at checkout, such as a gift note |
| `metadata` | `array` | What your own code wrote on the order |
| `actor` | `array` | [Who caused the event](docs/extensions/events#who-caused-the-event) |

A listener is never given the buyer's link to the order, or anything that could pay for it.

### Write listeners that can run twice

An admin can choose **Run fulfillment again** on an order, which sends `order.paid` a second time. So a listener must give the same result the second time: check whether you already did the work for this `orderId`. For `order.refunded`, check `transactionId`.

## Subscription events

| Event | When |
|---|---|
| `subscription.created` | The first payment was confirmed. Fires after that order's `order.paid`. |
| `subscription.renewed` | A later period was paid. Fires after that renewal order's `order.paid`. |
| `subscription.past_due` | A renewal charge failed. Once for each failed invoice. |
| `subscription.canceled` | The subscription ended for good. |

Every renewal is an order, so `order.paid` fires for each period too, with `kind` set to `renewal`. A listener that delivers something for every payment can listen to `order.paid` alone.

| Key | Type | Description |
|---|---|---|
| `subscriptionId` | `string` | The subscription's ID |
| `collection` | `string` | The collection subscriptions are kept in |
| `status` | `string` | `trialing`, `active`, `past_due`, `paused` or `canceled` |
| `email` | `string` | The subscriber's email |
| `customer` | `?array` | `{collection, id}` for a signed-in customer, `null` for a guest |
| `currency` | `string` | Three-letter code |
| `recurringTotal` | `int` | What every period costs, in minor units |
| `interval` | `string` | `day`, `week`, `month` or `year` |
| `intervalCount` | `int` | How many intervals make one period |
| `currentPeriodEnd` | `?string` | When the period paid for ends, ISO 8601 |
| `cancelAtPeriodEnd` | `bool` | The subscriber has canceled, and it ends with the period |
| `renewalCount` | `int` | How many periods have been paid after the first |
| `orderId` | `string` | The first order for `subscription.created`, the renewal order for `subscription.renewed`, `''` otherwise |
| `provider` | `string` | The payment provider's ID |
| `lines` | `array` | The lines billed every period, by line ID: `productId`, `variantId`, `sku`, `title`, `qty`, `unitPrice`, `lineTotal`, `discountTotal`, `meta`, `metadata`, `accessGroup` |
| `actor` | `array` | [Who caused the event](docs/extensions/events#who-caused-the-event) |

A provider can send the same news twice. Each of these events is sent once for the thing it is about: once per subscription for `created` and `canceled`, once per renewal order for `renewed`, once per failed invoice for `past_due`.


## Stock events

The store changes a product's stock itself: down when an order is paid, up when a refund's goods come back. It writes the new count without `object.updated`, so a sale does not run every automation you have on products. Listen for these instead:

| Event | When |
|---|---|
| `stock.changed` | Every time the store changes a count. |
| `stock.low` | Once, as a count comes down to **Low Stock At** (Settings → Commerce) with something still left. |
| `stock.out` | Once, as a count comes down to nothing, or below it for a product that takes back-orders. |

`stock.low` and `stock.out` fire after the `stock.changed` for the same sale. A count that goes straight to nothing fires `stock.out` only.

| Key | Type | Description |
|---|---|---|
| `collection` | `string` | The collection products are kept in |
| `productId` | `string` | The product |
| `variantId` | `string` | The variant whose count it was; empty for a product without variants |
| `title`, `sku` | `string` | As on the order line |
| `before`, `after` | `int` | The count before and after |
| `change` | `int` | `after` minus `before`: negative for a sale |
| `reason` | `string` | `sale` or `restock` |
| `orderId` | `string` | The order that caused it |
| `lowStockAt` | `int` | The store's Low Stock At when it happened |
| `actor` | `array` | [Who caused the event](docs/extensions/events#who-caused-the-event) |

A stock number you change by hand on the product's form is an ordinary edit: it fires `object.updated`, not these.

## Deliver something when an order is paid

A product's **Fulfillment Data** is copied onto its order line as `meta`. This listener issues a license for each line that asks for one, then marks the line delivered:

```php
use TotalCMS\Domain\Commerce\Order\OrderFulfillment;

$context->addEventListener('order.paid', function (array $payload) use ($context): void {
    foreach ($payload['lines'] as $lineId => $line) {
        if (($line['meta']['license'] ?? false) !== true || $line['fulfillmentStatus'] === 'fulfilled') {
            continue;
        }

        issueLicense($payload['email'], $line['productId'], $line['qty']);

        $context->get(OrderFulfillment::class)->markLineFulfilled($payload['orderId'], (string)$lineId, 'License issued');
    }
});
```

`markLineFulfilled()` is what tells the store the line is done. If your listener fails, the line stays unfulfilled, and after 15 minutes the order is flagged **Not delivered** in the admin.

## Extension points

### A payment provider

```php
$context->addPaymentProvider(new MyProvider(/* ... */));
```

A provider implements `TotalCMS\Domain\Commerce\Payment\PaymentProvider`: it starts a checkout for a total the store gives it, checks the signature on its own webhook, and turns each webhook into an event the store understands. It never decides a price, and it never marks an order paid itself.

The store's owner switches the provider on under **Settings → Commerce**. Its webhook address is `/commerce/webhook/{id}`, where `{id}` is what your provider's `id()` returns.

The bundled Stripe extension (`resources/extensions/totalcms/stripe`) is a complete example.

A provider that can bill a subscription also implements `TotalCMS\Domain\Commerce\Payment\SubscriptionProvider`: it cancels a subscription when asked, says where one stands, and gives the address of a page where the buyer changes their card. It turns its own webhooks about a subscription into `SubscriptionEvent`s. Until a provider does, a cart with a subscription in it does not offer that provider.

### A price that depends on the buyer

```php
$context->addPriceResolver(new UpgradePrice(), priority: 10);
```

A `PriceResolver` is asked for every cart line, and again at checkout, so what the buyer saw is what they are charged. Return a `Money` for your price, or `null` for no opinion. The first resolver to name a price sets it.

```php
use TotalCMS\Domain\Commerce\Contract\PriceResolver;

final class UpgradePrice implements PriceResolver
{
    public function id(): string
    {
        return 'upgrade';
    }

    public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money
    {
        return $product->id === 'pro' && $this->ownsStandard($ctx) ? Money::of(4900, $ctx->currency) : null;
    }
}
```

### Refuse a checkout

```php
$context->addCheckoutValidator(new NeedsAccount());
```

A `CheckoutValidator` sees the order the store built, before any payment is asked for. Return the reason to show the buyer, or `null` to allow it. It cannot change the order.

### Add a place to keep downloads

```php
$context->addDownloadSource(new VaultDownloads());
```

A `DownloadSource` is a place a digital product's download can be kept: a bucket, a media host. The store asks each source in turn (the product's own file, then extension sources, then the product's Download Link) and the first with something for the product delivers it.

```php
use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Domain\Commerce\Download\Deliverable;
use TotalCMS\Domain\Commerce\Download\Delivery;

final class VaultDownloads implements DownloadSource
{
    public function id(): string
    {
        return 'vault';
    }

    // Asked whenever an order is shown: keep it cheap.
    public function find(string $collection, array $product): ?Deliverable
    {
        $file = $this->vault->lookup($product['id']);

        return $file ? new Deliverable($file->name, $file->size, 'vaultFile') : null;
    }

    // Asked once for each download, after the store has counted it.
    public function deliver(string $collection, array $product): ?Delivery
    {
        $file = $this->vault->lookup($product['id']);

        return $file ? Delivery::redirect($file->name, $this->vault->signedUrl($file)) : null;
    }
}
```

Return `Delivery::redirect()` to send the buyer to an address, or `Delivery::stream()` to send the bytes yourself. A source never decides who may download: the store has already checked the buyer's link, that the order is still paid, and that downloads are left. A source that throws is passed over and logged.

### Write your own data on an order

```php
$context->addOrderBuildingListener(function (OrderDraftMetadata $meta, OrderDraft $draft): void {
    $meta->setOrder('campaign', 'spring-sale');
});
```

The listener runs while an order is built. It can write to the order's `metadata` and each line's, up to 4 KB in all. It cannot change a price. What it writes is in every order event's payload.

## What an extension cannot do

- Change a price after the store set it, except through a `PriceResolver`.
- Write to an order, a payment or a cart directly. Use `OrderFulfillment::markLineFulfilled()` and the extension points above.
