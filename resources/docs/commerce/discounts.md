---
title: "Discount Codes"
description: "Create discount codes for your store: percent off, an amount off, limits by product, date, and number of uses."
since: "3.7.0"
related:
  - commerce/products
  - commerce/cart-and-checkout
---

# Discount Codes

A discount code is something a buyer types at the cart to pay less. Codes live in the `discounts` collection. Add them under **Collections → Discounts**.

## Create a code

| Field | What it does |
|---|---|
| **ID** | The code itself: letters, numbers and hyphens, 3 to 40 characters. Buyers can type it in any case. |
| **Title** | A name for your team. Buyers never see it. |
| **Active** | Turn off to stop the code working without deleting it. |
| **Type** | **Percent off** or **Amount off**. (**Free shipping** is there for when [shipping rates arrive](docs/commerce/overview#not-in-this-version-yet).) |
| **Value** | For percent off, `15` means 15%. For amount off, an amount in your store's currency. |
| **Only These Products** and **Only These Categories** | Limit the code to some products. Leave both empty for the whole cart. |
| **Minimum Subtotal** | The cart must reach this amount for the code to work. |
| **Starts** and **Ends** | When the code works. Leave empty for no limit. |
| **Total Uses** | How many orders can use the code. `0` means no limit. |
| **Uses per Customer** | How many times one buyer can use it, counted by email address. `0` means no limit. |

**Used Count** is kept by the store. It goes up by one when an order that used the code is paid.

## How a code is applied

- An amount off is shared across the lines it applies to, so each line shows its part.
- A code never takes a line below zero.
- One code at a time. A second code replaces the first.
- The code is checked again at checkout. If it stopped being valid in between, checkout is refused and the cart says why.

## Put the code box on your site

The cart page and the slide-out cart include a code box. To put one somewhere else:

```twig
{{ cms.cart.discountForm() }}
```

To show what the code took off:

```twig
{% set discount = cms.cart.discount() %}
{% if discount %}
	<p>Code {{ discount.code }}: -{{ discount.formatted }}</p>
{% endif %}
<p>Total: {{ cms.cart.total()|money }}</p>
```

## Guessing is slowed down

One visitor can try 10 codes a minute. A wrong code, an expired one and one that is used up all get the same answer, so a guesser learns nothing.
