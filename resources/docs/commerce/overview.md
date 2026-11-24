---
title: "Commerce Overview"
description: "Sell products from a Total CMS site: a catalog, a cart, checkout through a payment provider, and orders in the admin. What Commerce does, what it needs, and how a sale works."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/products
  - commerce/cart-and-checkout
  - commerce/orders
---

# Commerce Overview

Commerce turns a Total CMS site into a store. You keep products in a collection, put a cart on your pages, and take payment through a provider such as Stripe. Orders appear in the admin.

Your catalog, carts and orders are stored on your own site, as collections, like the rest of your content. Card numbers are not: the buyer types those into the payment provider's form, and they never reach your server.

## What you need

- The **Pro edition**.
- The **Commerce add-on**.
- A **payment provider** extension, such as the bundled [Stripe](docs/extensions/stripe) extension.

A development license (a local site on `.test` or `localhost`) and a running trial include all three, so you can build and test a store before you buy.

On a site without the add-on, nothing about Commerce shows: no Orders page, no store settings, no product schema.

## What it does

- **Catalog.** Products with prices, photos, variants (sizes, colors), categories and tags.
- **Cart.** An add button, a mini cart, a slide-out cart and a full cart page, each one line of Twig.
- **Discount codes.** Percent off, an amount off, for the whole cart or for some products.
- **Checkout.** On the provider's page, or in the provider's form on your own page.
- **Orders.** A list you can filter and search, a page for each order, and a count of the orders that need you.
- **Fulfillment.** Mark items as sent, with a tracking number.
- **Refunds.** Ask the provider for a refund from the order's page.
- **Events.** Your own code can act when an order is paid, fulfilled, refunded or disputed.

## How a sale works

1. A visitor adds products to the cart. The cart holds what they chose and how many. It holds no prices.
2. At checkout, Total CMS reads the prices from your catalog, builds the order and works out the total. Nothing the browser sends can change a price.
3. Total CMS asks the payment provider to collect exactly that total, and the buyer pays the provider.
4. The provider tells your site the payment succeeded, in a signed message called a webhook. **Only then** is the order marked paid.
5. The order appears under **Orders** in the admin, the buyer gets a receipt, and your listeners are told.

Step 4 is the one that counts. The buyer coming back to your site proves nothing, because anyone can open that address. An order is paid when the provider says so.

## Where things are kept

Commerce adds these collections. You edit products and discount codes like any other collection. The rest are written by the store itself, and nobody can edit them by hand, an administrator included: that is what makes an order's record trustworthy.

| Collection | What it holds | Who writes it |
|---|---|---|
| `products` | Your catalog | You |
| `discounts` | Discount codes | You |
| `orders` | One object for each order | The store |
| `transactions` | Every movement of an order's money | The store |
| `carts` | Carts of signed-in customers | The store |

## Not in this version yet

These are designed and on the way. Their settings and fields are already there, and do nothing yet:

- **Shipping rates.** Checkout charges no shipping. [Tax](docs/commerce/tax) is in this version, from your own table of rates or worked out by Stripe Tax.
- **Stock counted down.** A product's stock stops a sale at zero. It does not go down as orders are paid.
- **File downloads.** A digital product can be sold. Its files are not delivered automatically.
- **Subscriptions.**
- **Returning items to stock** when you refund.

## Next

1. [Set up your store](docs/commerce/setup)
2. [Add products](docs/commerce/products)
3. [Put a cart and checkout on your site](docs/commerce/cart-and-checkout)
4. [Work with orders](docs/commerce/orders)
