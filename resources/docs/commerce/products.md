---
title: "Products"
description: "Add products to your store: prices, variants, photos, physical and digital products, and the fields your own code can use."
since: "3.7.0"
related:
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
| **Download** | Digital products only. The file a buyer gets after paying. See [Digital Delivery](docs/commerce/digital-delivery). |
| **Requires Shipping** | Whether checkout asks where to send it. |
| **SKU** | Your own stock code. Copied onto each order line. |
| **Images** | Product photos. The first is the main one. |
| **Description** | What the product page says. |
| **Category** and **Tags** | For grouping and filtering your catalog. A discount code can be limited to a category. |

## Variants

Variants are the choices a buyer makes: a size, a color, a monthly or a yearly plan. Add them in the **Variants** list on the product.

Each variant has its own title, and may have its own price, SKU, stock and photo. A variant with no price uses the product's price.

A product with variants is always bought as one of them. The cart refuses the product on its own.

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
