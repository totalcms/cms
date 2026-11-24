---
title: "Shipping"
description: "Charge for shipping in your store: one flat rate, or a rate by where the order is going, with free shipping over an amount."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/tax
  - commerce/cart-and-checkout
  - commerce/discounts
---

# Shipping

Your store can charge for shipping in one of three ways. Choose under **Shipping** in **Settings → Commerce**.

| Choice | What it does |
|---|---|
| **No shipping** | Orders ship, and the buyer is charged nothing for it. This is the default. |
| **Flat rate** | Every order that ships is charged the same amount. |
| **By zone** | An order is charged by where it is going. |

Shipping is charged once for an order, however many things are in it. An order with nothing to ship, such as a download, is never charged.

## A flat rate

Set **Flat Rate** to the amount, in your store's currency.

**Free Shipping Over** is optional. An order that comes to that amount or more ships free. Leave it empty and shipping is never free.

## Rates by zone

A zone is a group of places with one rate. Add a zone for each under **Shipping Zones**.

| Field | What to put |
|---|---|
| **Label** | The zone's name. The buyer sees it beside the shipping charge. |
| **Countries** | The countries in the zone. Leave empty for every country. |
| **Regions** | States or provinces, to limit the zone to part of its countries. Leave empty for the whole of each country. |
| **Rate** | The shipping charge for an order going there. |
| **Free Over** | Optional. An order at or over this amount ships free to this zone. |

### Which zone applies

An order ships at the rate of the **first** zone that covers its address, in the order you list them. So put the most exact zone first.

To charge $4 to California and Oregon, $7.50 to the rest of the United States, and $30 everywhere else, list three zones in this order:

1. **West Coast**: United States, regions `CA` and `OR`, rate `4`.
2. **United States**: United States, no regions, rate `7.50`.
3. **Rest of the world**: no countries, rate `30`.

A region is matched the way [tax rates match one](docs/commerce/tax#how-a-region-is-matched): for the United States and Canada, a code and the full name are the same place.

### Places you do not ship to

An address that no zone covers cannot be checked out. The buyer is told the order cannot be shipped there, on the checkout form, before they pay.

If you want to ship everywhere, end your list with a zone that has no countries.

## What "free over" compares

The amount the goods come to, after any discount code and before shipping and tax. A buyer who uses a code that takes their order below the amount pays for shipping.

A [discount code](docs/commerce/discounts) can also give free shipping by itself.

## Tax on shipping

When you charge tax from your own rates, **Tax Shipping** decides whether shipping is taxed too. See [Tax](docs/commerce/tax#what-is-taxed).

## What the buyer sees

The checkout form shows what the order comes to: the goods, the discount, shipping, tax and the total. It works shipping out again each time the buyer changes their address.

Until the buyer has given a country, a store that ships by zone cannot know the charge. The form says shipping is worked out from the address, and shows no amount.

The shipping charge is on the payment page, the order page, the receipt and the order in the admin.

## Not built in

- **Rates by weight or size.** A product's **Weight** is kept for extensions and is not used by these rates.
- **Live carrier rates** and **a choice of services**, such as standard or express. An order has one rate.
