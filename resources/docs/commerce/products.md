---
title: "Products"
description: "Add products to your store: prices, variants, photos, physical and digital products, and the fields your own code can use."
since: "3.7.0"
related:
  - commerce/name-your-price
  - commerce/digital-delivery
  - commerce/subscriptions
  - commerce/discounts
  - commerce/cart-and-checkout
  - twig/commerce
---

# Products

Products live in the `products` collection. Add and edit them under **Collections → Products**, like any other content.

## The fields that matter

| Field | What it does |
|---|---|
| **ID** | Used in the product's address and on every cart and order line. Do not change it once the product has sold. |
| **Title** | The product's name, as buyers see it. |
| **Status** | Only an **Active** product can be added to a cart. **Draft** and **Archived** products are hidden from visitors. |
| **Price** | The price of one, in your store's currency. |
| **Compare-at Price** | Shown as a crossed-out price. For display only. It is never charged. |
| **Type** | **Physical** or **Digital**. A digital product never asks for a shipping address. |
| **Download** | Digital products only. What a buyer gets after paying: by default a file on your site; your product schema can make it a link to a file kept elsewhere, or a file in your S3 bucket. See [Digital Delivery](docs/commerce/digital-delivery). |
| **Requires Shipping** | Whether checkout asks where to send it. |
| **SKU** | Your own stock code. Copied onto each order line. |
| **Images** | Product photos. The first is the main one. |
| **Description** | What the product page says. |
| **Category** and **Tags** | For grouping and filtering your catalog. A discount code can be limited to a category. |
| **Let Buyers Choose the Price** | Turns the product into a pay-what-you-want or donation product. See [Name Your Price](docs/commerce/name-your-price). |

## Product parts

A product schema is built from five **parts**. Each part is a small reserved schema that holds one group of fields. The bundled `commerce-product` schema, the one the store installs, takes all five. A store with simpler needs can build its own product schema from only the parts it uses.

| Part | Fields it adds | Take it when |
|---|---|---|
| `commerce-product-base` | **ID**, **Title**, **Status**, **Type**, **SKU**, **Price**, **Compare-at Price**, **Taxable**, **Let Buyers Choose the Price**, **Minimum Price**, **Suggested Prices**, **Description**, **Images**, **Category**, **Tags**, **Fulfillment Data**, **Created**, **Updated** | Always. A product needs an ID, a title and a price. |
| `commerce-product-subscription` | **Billing**, **Every**, **Interval Count**, **Grants Access Group** | You sell subscriptions or memberships, or let buyers choose a one-time or monthly option. |
| `commerce-product-inventory` | **Track Inventory**, **Stock**, **Allow Backorder**, **Requires Shipping**, **Weight** | You sell something that ships, or you count stock. |
| `commerce-product-variants` | **Variants** | A buyer chooses between sizes, colors or plans. |
| `commerce-product-digital` | **Download** | You deliver a file after payment. |

A part brings its own section of the product form, so a schema built from parts has a usable form without a layout of its own. Your own fields go at the end. See [Form layouts](docs/schemas/formgrid) for how the parts' layouts are joined, and [`inheritFrom`](docs/schemas/reference#inheritfrom) for how inheritance works.

### A product without a part

A product that does not have a part behaves as if that part were switched off.

- **No inventory part.** Nothing ships, so checkout never asks for a shipping address. No stock is counted, so the product never runs out.
- **No subscription part.** The product is bought once, and gives no access group.
- **No variants part.** The product is bought on its own, with no choices.
- **No digital part.** The product has no download.
- **No base part.** You do not have a product. Always take `commerce-product-base`.

## Make a product schema of your own

This walk-through sells ebooks. Each book has an author and an ISBN, and the buyer downloads a file. Nothing ships and there is no stock to count, so the schema takes only two parts.

### 1. Write the schema

Save this as `book.json`. A property you define in your own schema is added to the ones the parts bring.

```json
{
  "id": "book",
  "type": "object",
  "title": "Book",
  "description": "An ebook sold as a download.",
  "inheritFrom": ["commerce-product-base", "commerce-product-digital"],
  "properties": {
    "author": {
      "type": "string",
      "field": "text",
      "label": "Author",
      "help": "Who wrote the book. Shown on the book's page."
    },
    "isbn": {
      "type": "string",
      "field": "text",
      "label": "ISBN",
      "help": "The book's ISBN, if it has one."
    }
  }
}
```

Add it with `tcms schema:import book.json`, or build it under **Schemas** in the admin. `tcms schema:lint book` checks it.

The schema has no `formgrid`, so its form is the parts' layouts joined. The form adds **Author** and **ISBN** after them, each full width. To place them yourself, write a `formgrid`. A schema with its own layout gets exactly that layout.

### 2. Make a collection on it

Make a collection with the schema `book` and the ID `books`, under **Collections** or with `tcms collection:create books --schema=book --name=Books`.

### 3. Give the collection a File Access Group

Open the new collection, find **File Access Groups**, and choose `admin`.

The store does this for the `products` collection it installs, and not for a collection you make. Without a group, the file can be fetched from its ordinary download address by anyone who knows a book's ID. A buyer does not need the group: their signed link works without one. The dashboard's **Store** widget warns you while a products collection with a download has none, or has the group `default`. See [Who else can get the file](docs/commerce/digital-delivery#who-else-can-get-the-file).

### 4. Point the store at the collection

The store reads its products from the collection set in `commerce.collections.products`. It is `products` unless you say otherwise. Add the key to the `tcms.php` in your document root. It is deep-merged, so you set only this one key:

```php
<?php

return [
	'commerce' => [
		'collections' => ['products' => 'books'],
	],
];
```

This is a setting in the site's config file. It is not a field on the **Settings → Commerce** page, so nothing saved there replaces it. See [Configuration](docs/operations/configuration).

When to run `tcms commerce:install`:

- **Commerce not installed yet:** make the collection (step 2), set this key, then run `tcms commerce:install`. The install takes `books` as the products collection, because its schema is built on `commerce-product-base`, and does not make a `products` collection. It leaves the collection's settings as they are, its File Access Groups too.
- **Commerce already installed:** set the key. Nothing needs to run again: the store reads the key every time it looks for a product. The `products` collection the install made stays. Delete it if you do not need it.

Make the collection first. When the key names a collection that does not exist, the install makes one on the full `commerce-product` schema.

### 5. Add a book

Open **Collections → Books** and add one. Set the **Title**, the **Price**, the **Type** to **Digital**, upload the **Download**, and set **Status** to **Active**.

A product in a collection of your own is bought the same way as any other: `cms.cart.addButton('my-book')`.

A store has one products collection. Orders hold a copy of what was sold, so changing the collection later does not change old orders.

## Variants

Variants are the choices a buyer makes: a size, a color, a monthly or a yearly plan. Add them in the **Variants** list on the product.

Each variant has its own title, and may have its own price, SKU, stock and photo. A variant with no price uses the product's price.

A product with variants is always bought as one of them. The cart refuses the product on its own.

A variant can also be billed differently from its product: one time, or as a subscription. See [Subscriptions](docs/commerce/subscriptions#one-time-and-monthly-on-one-product).

## Stock

Turn on **Track Inventory** and set **Stock** to stop selling when a product runs out. For a product with variants, each variant has its own stock.

- At zero, the product cannot be added to a cart, and checkout refuses an order for it.
- **Allow Backorder** keeps selling at zero. The count then goes below zero, which tells you how many you owe.

### Stock counts itself down

When an order is paid, what it sold is taken out of stock. It is taken once, however many times the payment is reported.

Nothing is taken for an order that was started and never paid. So two buyers can both check out the last one. Each is allowed through, the count is taken down as each pays, and the second order is flagged **Oversold**: the buyer has paid, and you choose between sending it later and a refund. See [Orders that need attention](docs/commerce/orders#orders-that-need-attention).

### Putting stock back

A refund does not put anything back by itself, because the goods may never come back. When you refund from the order's page, tick **Return the items to stock** if they are. The count goes back up when the payment provider confirms the refund, once for the order.

A refund made at the payment provider puts nothing back. Change the product's stock by hand.

### Knowing when stock runs low

The dashboard's **Running low** list shows every product and variant at or under **Low Stock At**, a number you set in **Settings → Commerce** (5 unless you change it).

To be told as it happens, make an [automation](docs/automations/overview) on the `stock.low` or `stock.out` event. Each fires once as a count comes down past the line. See [Stock events](docs/commerce/events-and-extensions#stock-events).

## Prices are read at checkout

A cart holds the product and the quantity. It holds no price. Every time a cart is shown, and again when the buyer checks out, the price is read from your catalog.

So when you change a price, carts show the new one at once. And nothing a browser sends can set a price.

An order is different. It keeps a copy of each line as it was sold: the title, the price and the quantity. Changing or deleting a product later never changes an order.

## Data for your own code

**Fulfillment Data** takes free-form JSON. It is copied onto each order line when the product sells, as the line's `meta`, so code that fulfills the order has what it needs:

```json
{"edition": "pro", "seats": 5}
```

See [Events and Extensions](docs/commerce/events-and-extensions) for how to act on a paid order.

## Showing products on your site

```twig
{% for product in cms.commerce.products({sort: 'title'}).objects %}
	<article>
		<h2><a href="/shop/{{ product.id }}">{{ product.title }}</a></h2>
		<p>{{ cms.commerce.price(product.id).formatted }}</p>
		{{ cms.cart.addButton(product.id) }}
	</article>
{% endfor %}
```

Every function is in the [Commerce Twig reference](docs/twig/commerce).
