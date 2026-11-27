---
title: "Name Your Price"
description: "Let buyers choose what they pay: pay-what-you-want products and donations, one time or every month. The rules for an amount, and a donation product built step by step."
since: "3.7.0"
related:
  - commerce/products
  - commerce/subscriptions
  - commerce/cart-and-checkout
  - commerce/events-and-extensions
---

# Name Your Price

A **name-your-price** product lets the buyer type the amount. Use it for pay what you want, tips and donations. A donation can be a single gift or a monthly one.

The store sets the limits. The buyer chooses an amount inside them.

## Turn it on for a product

Open the product. Under **Pricing**, set these fields:

| Field | What it does |
|---|---|
| **Let Buyers Choose the Price** | Switches the feature on. Off by default. |
| **Price** | The amount the field starts with. It is not charged unless the buyer leaves it. |
| **Minimum Price** | The least a buyer may pay. Default `0`. |
| **Suggested Prices** | Amounts offered as one-click buttons, such as `5`, `10` and `25`. Optional. |

One store setting sets the top end. In **Settings → Commerce**, under **Limits**, **Highest Price a Buyer Can Enter** is the most any buyer may type. The default is `10000`, in your store's currency. It catches a slip of the finger.

**Compare-at Price** is ignored on these products.

These fields come from the `commerce-product-base` part. The bundled `products` collection has them already. See [Product parts](docs/commerce/products#product-parts).

## The rules for an amount

Total CMS checks every amount, no matter what the browser sends.

- The amount is a plain decimal number, such as `15` or `12.50`. Text, a negative number, or a number written with an exponent, such as `1e3`, is refused.
- It is rounded to the smallest unit of your currency: cents for USD, whole yen for JPY.
- It is at most **Highest Price a Buyer Can Enter**.
- It is at least the product's **Minimum Price**.
- Any amount above zero is at least 50 of the currency's smallest unit: 0.50 in USD or EUR, 50 in JPY. Payment providers refuse a smaller charge.
- Zero is allowed only for a one-time purchase whose **Minimum Price** is `0`. The order is then free, and checkout completes it without taking a payment.
- A monthly, or other recurring, amount can never be zero.

An amount that breaks a rule is refused with a message that names the limits, such as "Enter an amount from 5.00 to 10000.00."

A product with **Let Buyers Choose the Price** off ignores any amount it is sent. The catalog price is charged.

## What the cart does with it

- A name-your-price line is always **quantity 1**. There is one line for each product and variant.
- Adding the product again **replaces** the amount. It does not add a second line.
- Prices are read again every time the cart is shown. If the product's rules change while it sits in a cart, the line is removed and the buyer sees a notice. That happens when the minimum is raised above the amount, when the highest price comes down below it, or when the product stops letting buyers choose.
- **Discount codes** leave a named price alone, in the cart and on the order. A buyer who chose their amount is not discounted from it. A cart that holds only name-your-price products refuses every discount code, free shipping codes too, because there is nothing in it the code can apply to.
- **Extension price resolvers** do not run for it either. A member price never overrides what a donor chose. See [A price that depends on the buyer](docs/commerce/events-and-extensions#a-price-that-depends-on-the-buyer).
- **Tax** follows the product's **Taxable** setting, as for any product. Turn **Taxable** off for a donation.

## What the buyer sees

The add-to-cart helpers show an amount field when the product lets the buyer choose the price. `cms.cart.addButton()` and `cms.cart.variantPicker()` both do it. There is no new function to call. See [Cart and Checkout](docs/commerce/cart-and-checkout#a-name-your-price-product).

## Monthly amounts

A monthly amount the buyer names is a subscription billed from that amount. So the payment provider must bill from the amount it is sent, instead of from a plan you made ahead of time.

- **Stripe** does.
- **PayPal** has no subscription support at all, so it cannot bill any subscription. See [Subscriptions](docs/commerce/subscriptions).

If no payment provider you have turned on can bill the amount, the cart refuses the line with `subscription_unavailable`, and the buyer is told subscriptions cannot be bought right now. A one-time amount works with every provider.

Extension authors: see [Payment providers and monthly amounts](docs/commerce/events-and-extensions#a-payment-provider).

A monthly amount is fixed when the subscription starts. A subscriber cannot change it. To give a different amount, they cancel and give again.

## Walk-through: a donation product

You will make one product that takes a donation. The buyer picks **One time** or **Monthly**, then types an amount. It needs Stripe, because of the monthly option.

### 1. Write the schema

A donation is built from three parts: the base, the subscription part (for the monthly option) and the variants part (to offer the choice). Save this as `donation.json`:

```json
{
  "id": "donation",
  "type": "object",
  "title": "Donation",
  "description": "A donation that can be one time or monthly.",
  "inheritFrom": [
    "commerce-product-base",
    "commerce-product-subscription",
    "commerce-product-variants"
  ]
}
```

Add it with `tcms schema:import donation.json`. It has no fields of its own, and no `formgrid`, so its form is the three parts' layouts joined.

### 2. Make the collection and point the store at it

Make a collection with the schema `donation` and the ID `donations`. Then open **Settings → Commerce**, find **Collections**, and choose it under **Products**, as in [Make a product schema of your own](docs/commerce/products#make-a-product-schema-of-your-own), step 4.

- **Commerce not installed yet:** make the collection, choose it, then run `tcms commerce:install`. The install takes `donations` as the products collection, because its schema is built on `commerce-product-base`. It does not make a `products` collection.
- **Commerce already installed:** choose the collection and save. Nothing needs to run again: the store reads the setting every time it looks for a product. The `products` collection the install made stays. Delete it if you do not need it.

A donation has no download, so it needs no File Access Group.

A store has one products collection. To sell a donation beside other products, build one schema with every part you need and add the donation as a product in that collection.

### 3. Add the product

Add a product to **Donations**:

| Field | Value |
|---|---|
| **Title** | `Support Our Work` |
| **ID** | `give` |
| **Type** | **Physical**, the default. The `donation` schema has no inventory part, so the product never ships: checkout never asks for an address, and a paid order needs no fulfillment and completes on its own. **Digital** works the same way here: a digital product with no download has nothing to send, so its orders complete once paid. |
| **Status** | **Active** |
| **Let Buyers Choose the Price** | On |
| **Price** | `25`, the amount the field starts with |
| **Minimum Price** | `5` |
| **Suggested Prices** | `10`, `25`, `50` |
| **Taxable** | Off |

### 4. Add two variants

In **Variants**, add two. A variant with no **Billing** is billed as its product is. These two say how they are billed:

| Variant ID | Title | Billing | Billed Every |
|---|---|---|---|
| `once` | `One time` | **One time** | |
| `monthly` | `Monthly` | **Subscription** | **Month**, **Interval Count** `1` |

### 5. Put it on a page

```twig
{{ cms.cart.variantPicker('give', {label: 'Give'}) }}
```

The buyer sees a choice between **One time** and **Monthly**, an amount field that starts at 25 with buttons for 10, 25 and 50, and a **Give** button.

The cart holds one line. At checkout, a one-time gift is one payment. A monthly gift is a subscription that bills the amount the donor typed every month. See [Subscriptions](docs/commerce/subscriptions).

## Next

- [Subscriptions](docs/commerce/subscriptions) for billing on a variant, and what a subscriber sees.
- [Cart and Checkout](docs/commerce/cart-and-checkout) for the add helpers and the cart API.
