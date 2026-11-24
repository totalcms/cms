---
title: "Tax"
description: "Charge sales tax or VAT from your own table of rates: a rate for a country or for one state, added to prices or already inside them."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/products
  - commerce/cart-and-checkout
---

# Tax

Your store can charge tax from a table of rates that you keep. You give a rate for a country, or for one state or province of it, and the store adds the tax for buyers there.

This is a simple table. It does not know which rates the law asks for, which products are taxable where, or the local rates inside a state. Those are yours to decide and to keep up to date.

## Turn tax on

Open **Settings → Commerce** and set **Tax**:

| Choice | What it does |
|---|---|
| **No tax** | No tax is charged. This is the default. |
| **Added to prices** | Tax is added to the order at checkout. A $20.00 product taxed at 10% costs $22.00. |
| **Included in prices** | Your prices already include the tax. A $20.00 product still costs $20.00, and the order says how much of it is tax. |

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

The store works out the tax and asks the payment provider for the whole total. It works the same with every provider.

Do not also switch on a provider's own tax feature, such as Stripe Tax, for the same store. The provider would then charge more than the order's total, and the order would be held for review.
