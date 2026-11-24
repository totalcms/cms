---
title: "Tax"
description: "Charge sales tax or VAT from your own table of rates, or let Stripe Tax work out the exact rate for each buyer."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/products
  - commerce/cart-and-checkout
  - extensions/stripe
---

# Tax

Your store can charge tax in one of two ways.

- **From your own table of rates.** You give a rate for a country, or for one state or province of it, and the store adds the tax for buyers there. It is a simple table. It does not know which rates the law asks for, which products are taxable where, or the local rates inside a state. Those are yours to decide and to keep up to date.
- **Worked out by Stripe.** [Stripe Tax](#let-stripe-work-out-the-tax) finds the exact rate for each buyer's address, local rates included, and adds it when they pay. It is a paid Stripe service.

Most of this page is about your own table. The last section is about Stripe Tax.

## Turn tax on

Open **Settings → Commerce** and set **Tax**:

| Choice | What it does |
|---|---|
| **No tax** | No tax is charged. This is the default. |
| **Added to prices** | Tax is added to the order at checkout. A $20.00 product taxed at 10% costs $22.00. |
| **Included in prices** | Your prices already include the tax. A $20.00 product still costs $20.00, and the order says how much of it is tax. |
| **Worked out by the payment provider** | Stripe Tax works out the tax and adds it when the buyer pays. Your table of rates is not used. See [Let Stripe work out the tax](#let-stripe-work-out-the-tax). |

## Add your rates

Under **Tax Rates**, add one row for each place you charge tax.

| Field | What to put |
|---|---|
| **Country** | The country the rate is for, chosen by name. **Everywhere** is a rate for buyers in any country that has no rate of its own. |
| **Region** | A state or province, such as `CA`. Leave it empty for the whole country. |
| **Rate (%)** | The percentage, such as `10` or `8.875`. Up to four decimals. |
| **Label** | What the buyer sees, such as *Sales tax* or *VAT*. Empty shows *Tax*. |

To charge 10% to buyers in California and nothing to anyone else, add one row: **United States**, `CA`, `10`.

### Which rate applies

The most exact row for the buyer's address wins:

1. a row for their region,
2. then a row for their country,
3. then a row for **Everywhere**.

A buyer with no matching row pays no tax.

### How a region is matched

Buyers type their state as they please: `CA`, `ca` or `California`. For the United States and Canada, the store knows a state's or province's code and its full name are the same place, so a row for `CA` matches all three.

For any other country, a region matches when it is written the same, whatever the capitals. Write it each way a buyer might, with commas between:

```text
NSW, New South Wales
```

## Where the buyer is

Tax goes by an address:

- **An order that ships** is taxed by its shipping address.
- **An order with nothing to ship**, such as a download, has no address. When tax is on, the checkout form asks the buyer for their **Country** and **State or region**.

Checkout is refused without a country. It is also refused without a region when you have a rate for one region of the buyer's country, because the tax depends on it.

## What is taxed

- **Each product** has a **Taxable** switch, on by default. Switch it off for a product you do not tax.
- **A discount** comes off first. A line is taxed on what the buyer pays for it.
- **Shipping** is taxed only when **Tax Shipping** is on.

Tax is worked out on each line and rounded to the nearest cent, a half going up. The lines' tax adds up to the order's tax exactly.

## Where the buyer sees it

The tax is on the payment page, on the order page and on the receipt, each under the label you gave the rate. The cart page says that tax is worked out at checkout: it does not show the amount, because the buyer has not said where they are yet.

## With payment providers

With your own table, the store works out the tax and asks the payment provider for the whole total. It works the same with every provider.

## Let Stripe work out the tax

Set **Tax** to **Worked out by the payment provider** and Stripe Tax takes over:

1. Your store builds the order without tax and asks Stripe for that total.
2. Stripe asks the buyer for the address it needs, works out the tax for that address, and adds it on its payment form.
3. When the buyer has paid, Stripe tells your store how much tax it charged. The order takes its tax and its total from the payment.

So the buyer first sees the tax on the payment form, not in the cart. The order page and the receipt show it once the order is paid.

### What Stripe needs first

Stripe Tax is set up in your Stripe account, not here. It needs two things:

- **Your head office address**, in the Stripe dashboard under **Tax**.
- **A registration for each place you collect tax**, under **Tax → Registrations**. Stripe works out tax only for places you are registered in. For a buyer anywhere else the tax is zero.

Your test mode has its own Stripe Tax settings and its own registrations. Set them up there too before you test.

Check that Stripe is ready from the command line:

```bash
tcms stripe:tax
```

It asks Stripe, with the key of the mode the Stripe extension is in, and says what is missing. It changes nothing.

Run this check before you switch the setting on. If Stripe Tax is not ready, a buyer can still check out, but is charged no tax, and nothing on your site says so. The reason is in the commerce log.

### What is taxed

- **Where:** an order that ships is taxed by the address it ships to. An order with nothing to ship is taxed by the billing address Stripe asks the buyer for. The checkout form does not ask for a country or region of its own.
- **Your prices:** in the Stripe extension's settings, **Stripe Tax: Your Prices** says whether the tax is added on top of your prices or is already inside them.
- **Products:** Stripe taxes a product under the default product tax code of your Stripe account. A product with **Taxable** switched off is sent to Stripe as not taxable.
- **Shipping** is sent to Stripe as shipping, and Stripe taxes it by the rules of the buyer's place. **Tax Shipping** is not used.
- **A discount** comes off first, as with your own table.

### Other payment providers

Only a provider that can work out tax does. Stripe can. An order paid with a provider that cannot carries no tax.

Do not switch on Stripe Tax in some other way for a store that uses its own table of rates. Stripe would then charge more than the order's total, and the order would be held for review.

### Refunds

A refund from the order's page can return up to all that was paid, tax included.
