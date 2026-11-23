---
title: "Commerce"
description: "Twig reference for a Total CMS store: cms.commerce for products, prices and orders, cms.cart for the cart and checkout, and the money filter."
since: "3.7.0"
related:
  - commerce/cart-and-checkout
  - commerce/products
  - commerce/discounts
---

# Commerce in Twig

Two objects and one filter. `cms.commerce` reads your catalog and orders. `cms.cart` is the visitor's cart. `money` writes an amount for a person.

On a site without Commerce none of these throws: lists are empty, and everything else is `null`, `false` or `''`.

## Amounts are whole numbers

Every amount in a cart or an order is a whole number of the currency's smallest unit: `1999` is $19.99. Print one with the `money` filter:

```twig
{{ 1999|money }}            {# $19.99, in the store's currency #}
{{ 1999|money('EUR') }}     {# €19.99 #}
```

A product's own `price` field is different: it is written as you typed it (`19.99`). Use `cms.commerce.price()` to get what a product costs, ready to print.

## `cms.commerce`

| Function | Returns |
|---|---|
| `enabled()` | `true` when the store can sell: Commerce is on, and a payment provider is ready. |
| `available()` | `true` when the site has Commerce, even before a provider is set up. |
| `currency()` | The store's currency code. |
| `products(options)` | Active products. Takes the options of `cms.collection.query()`: `filter`, `sort`, `search`, `limit`, `page`. Returns `{objects, total, page, pages, hasMore}`. |
| `product(id)` | One product, with all its fields, or `null`. Visitors get active products only. |
| `price(productId, variantId)` | What it costs this buyer: `{amount, listAmount, currency, formatted, listFormatted, source}`, or `null`. |
| `inStock(productId, variantId)` | Whether it can be bought now. |
| `providers()` | The ways to pay: a list of `{id, label}`. |
| `order(orderId, token)` | One order, for someone holding its signed link, or `null`. |
| `orders(limit)` | The signed-in customer's orders, newest first. Empty for a guest. |
| `embed(order, token)` | The payment form for an order, on [your own payment page](docs/commerce/cart-and-checkout#paying-on-your-own-page). |
| `format(amount, currency)` | The same as the `money` filter. |

```twig
{% set price = cms.commerce.price(product.id) %}
{% if price.listAmount > price.amount %}<s>{{ price.listFormatted }}</s>{% endif %}
<strong>{{ price.formatted }}</strong>
{% if not cms.commerce.inStock(product.id) %}<p>Sold out</p>{% endif %}
```

## `cms.cart`

### Reading the cart

| Function | Returns |
|---|---|
| `count()` | How many items are in the cart, counting quantities. |
| `isEmpty()` | `true` for an empty cart. |
| `lines()` | The lines: `lineKey`, `productId`, `variantId`, `title`, `variantTitle`, `image`, `qty`, `unitPrice`, `lineTotal`, `discountTotal`. |
| `subtotal()` | The lines added up. |
| `discount()` | The code on the cart, `{code, type, amount, formatted}`, or `null`. |
| `total()` | The subtotal less the discount. |
| `notices()` | What changed since the buyer last looked, such as a product that was removed. Each is given once. |
| `attribute(key)` | A value the buyer gave at the cart, such as a gift note. |

### Ready-made pieces

Each prints HTML. See [Cart and Checkout](docs/commerce/cart-and-checkout) for how they fit together.

| Function | Prints |
|---|---|
| `addButton(productId, options)` | An "Add to cart" button. Options: `variant`, `qty`, `label`, `class`. |
| `variantPicker(productId, options)` | A choice of variants, a quantity and a button. Options: `qty`, `label`, `class`. |
| `toggle(options)` | The button that opens the slide-out cart, with the count. Options: `label`, `class`. |
| `drawer(options)` | The slide-out cart. Options: `side`, `title`, `checkoutUrl`, `openOnAdd`, `class`. |
| `widget()` | The mini cart. |
| `page()` | The full cart. |
| `discountForm()` | The discount code box. |
| `checkoutForm(options)` | The checkout form. Options: `label`, `class`. |

```twig
{{ cms.cart.addButton(product.id, {label: 'Buy now', qty: 2, class: 'button primary'}) }}
```
