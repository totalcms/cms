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
| `kind` | `string` | `checkout` |
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
| `lines` | `array` | The order's lines, by line ID: `productId`, `variantId`, `sku`, `title`, `type`, `qty`, `unitPrice`, `lineTotal`, `discountTotal`, `meta`, `metadata`, `fulfillmentStatus` |
| `attributes` | `array` | What the buyer gave at checkout, such as a gift note |
| `metadata` | `array` | What your own code wrote on the order |
| `actor` | `array` | [Who caused the event](docs/extensions/events#who-caused-the-event) |

A listener is never given the buyer's link to the order, or anything that could pay for it.

### Write listeners that can run twice

An admin can choose **Run fulfillment again** on an order, which sends `order.paid` a second time. So a listener must give the same result the second time: check whether you already did the work for this `orderId`. For `order.refunded`, check `transactionId`.

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
