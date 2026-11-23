---
title: "Set Up Your Store"
description: "Install Commerce, choose a currency, connect a payment provider, and decide who on your team can see orders."
since: "3.7.0"
related:
  - commerce/overview
  - extensions/stripe
  - auth/access-groups
---

# Set Up Your Store

## 1. Install the store

Commerce needs its collections before it can do anything. Create them from the command line:

```bash
tcms commerce:install
```

This adds the `products`, `orders`, `transactions`, `carts`, `discounts` and `subscriptions` collections, and two email templates: `commerce-receipt` and `commerce-payment-failed`. It is safe to run again: it adds what is missing and leaves the rest alone.

The command refuses on a site without the Commerce add-on.

## 2. Choose your currency

Open **Settings → Commerce** and set **Store Currency** to a three-letter code such as `USD`, `EUR` or `GBP`.

Choose it before your first order. Every price and every order is in this one currency, and changing it later does not convert what is already there.

## 3. Connect a payment provider

A payment provider is an extension. Enable one, give it its keys, then tick it under **Payment Providers** in **Settings → Commerce**.

The [Stripe extension](docs/extensions/stripe) ships with Total CMS. Its page walks through keys, the webhook and a test purchase.

A buyer can check out once a provider is enabled, has its keys, and is chosen here. Until then your pages can show products, and `cms.commerce.enabled()` is `false`.

## 4. Choose where the buyer pays

**Checkout** in **Settings → Commerce** has two choices:

| Choice | What the buyer sees |
|---|---|
| **On the provider's page** | They leave your site to pay, and come back. The default, and the least to set up. |
| **On this site** | The provider's form is shown inside a page of your site. The provider must support it, and may need another key. |

Either way the provider draws the form and takes the card. See [Cart and Checkout](docs/commerce/cart-and-checkout#paying-on-your-own-page).

## 5. Let your team see orders

Orders hold your buyers' names, addresses and what they paid, so seeing them is a permission of its own.

- A **super admin** sees and does everything.
- Anyone else needs an access group with **Commerce Orders** switched on. Open **Utilities → Access Groups**, edit a group, and tick it under **Dashboard Features**.

With that permission a team member can read orders, mark items as sent, keep notes and resend a receipt. Refunding, cancelling and running fulfillment again stay with super admins.

No built-in group has the permission except `admin`. After you add Commerce to a site, grant it to whoever needs it.

## Settings

These are under **Settings → Commerce**.

| Setting | What it does |
|---|---|
| **Store Currency** | The currency of every price and order. |
| **Store Name** | The name receipts come from: their sender name, and in their subject and text. Empty uses the site's name. |
| **Store Email** | Where a buyer's reply to a receipt goes, and the address the receipt says to write to. Empty sends replies to the address your site sends email from. |
| **Customer Accounts Collection** | The auth collection customers sign in to, so they can see their orders. Empty means guest checkout only. |
| **Payment Providers** | The ways a buyer can pay: tick each one to offer. Checkout lists them in the order shown here. |
| **Checkout** | Where the buyer pays: the provider's page, or this site. |
| **First Order Number** | The number your first order gets. Default `1001`. A number is never used twice. |
| **Order Number Prefix** | Shown before an order number, as in `#1001`. |
| **Receipt Email** | The mailer template sent when an order is paid. Empty uses `commerce-receipt`. |
| **Order Page** | A Site Builder page that shows an order after payment. Empty uses the built-in page. |
| **Payment Page** | A Site Builder page that holds the payment form when checkout is on this site. Empty uses the built-in page. |
| **Most Lines in a Cart** | Default `100`. |
| **Most of One Item** | The highest quantity of one item. Default `99`. |
| **Checkout Attributes** | Extra values checkout may take from the buyer, by name, such as `giftNote`. Anything not named is dropped. |

The settings for tax, shipping, downloads and subscriptions are for features that are [not in this version yet](docs/commerce/overview#not-in-this-version-yet).

## Receipts

`tcms commerce:install` adds a mailer template called `commerce-receipt`. It is your receipt unless you choose another under **Receipt Email**. Edit it under **Mailer** like any other template. It is sent once when an order is paid. It comes from your **Store Name** and a reply to it goes to your **Store Email**. In the template the order is `data`, as in `{{ data.number }}`. Any template, yours or an email's, can print the two settings with `cms.commerce.storeName()` and `cms.commerce.storeEmail()`.

Receipts go out through your site's email settings. If email is not set up, no receipt is sent.

A receipt is put in the site's job queue when the order is paid, and the job runner sends it. A site with no job runner takes payments and never sends a receipt, so set one up before you sell: see [Cron URLs](docs/operations/cron-urls). On a local site, run `tcms jobs:process` by hand to send what is waiting.

## Stacks sites

A site built with Stacks sends only listed addresses to Total CMS. Add this rule beside the others in your `.htaccess`, so the cart, checkout and webhook addresses reach it:

```apache
RewriteRule ^commerce/.+$ rw_common/plugins/stacks/tcms/public/index.php [QSA,L]
```

See [Apache configuration](docs/operations/apache) for the full list.

## One server writes orders

Order numbers and stock are kept in files on your server. Run your store from one server. Two servers that each write their own copy of the data would hand out the same order number twice.
