---
title: "PayPal Payments (Bundled Extension)"
description: "Take payments in a Total CMS store with PayPal. Covers the app's credentials, the webhook, sandbox testing, how a PayPal payment is captured and what happens when one goes wrong."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/orders
  - commerce/tax
  - extensions/stripe
---

# PayPal Payments

The PayPal extension lets a Total CMS store take payments with PayPal. The buyer approves the payment on PayPal's own page, with their PayPal balance, bank or card, and comes back to your site.

It needs the **Pro edition** and the **Commerce add-on**. A development license and a running trial include both.

## How a payment works

A PayPal payment has two steps, where a card payment has one.

1. Your store builds the order and asks PayPal for a payment of exactly its total.
2. The buyer is sent to PayPal and **approves** the payment. No money has moved yet.
3. Your store **captures** the payment. This is when the money moves.
4. PayPal calls your store's webhook to say the capture went through, and the order becomes **Paid**.

Your store captures as soon as it learns of the approval, whichever of these comes first:

- the buyer comes back to your site,
- PayPal's webhook says the buyer approved,
- [`tcms commerce:reconcile`](docs/commerce/orders#orders-that-stay-pending) finds an approved order still waiting.

So a buyer who approves and closes the window before coming back is still charged, and still gets their order. The capture is asked for under one key each time, so an order is captured once.

## Setup

### 1. Enable the extension

PayPal ships with Total CMS as a bundled extension, disabled by default. Enable it in **Admin → Extensions**, or from the command line:

```bash
tcms extension:enable totalcms/paypal
```

### 2. Make an app at PayPal

1. Sign in at [developer.paypal.com](https://developer.paypal.com) and open **Apps & Credentials**.
2. Choose **Sandbox** to test, or **Live** for real payments.
3. Create an app, and copy its **Client ID** and **Secret**.

Open the extension's settings from **Admin → Extensions**. Leave **Mode** on **Sandbox** and paste the two values into **Sandbox Client ID** and **Sandbox Secret**.

### 3. Add the webhook

On the app's page at PayPal, add a webhook that points at your site:

```text
https://example.com/commerce/webhook/paypal
```

Choose these events:

- Checkout order approved
- Payment capture completed
- Payment capture denied
- Payment capture refunded
- Customer dispute created
- Customer dispute resolved

PayPal shows a **Webhook ID** when you save. Paste it into **Sandbox Webhook ID**.

The extension is not ready, and buyers are not offered PayPal, until all three values are filled in. Without the Webhook ID your store could not tell a real event from a forged one, and an order would be paid for and never marked paid.

### 4. Switch PayPal on in your store

In **Settings → Commerce**, tick **PayPal** under **Payment Providers**.

### 5. Make a test purchase

PayPal's sandbox comes with two made-up accounts: a business one, which is your store, and a personal one, which is your test buyer. Their sign-in details are on developer.paypal.com under **Testing Tools → Sandbox Accounts**.

Buy something in your store, choose PayPal, sign in as the personal account and choose **Pay Now**. The order becomes **Paid** a few seconds after you come back.

### 6. Go live

Make a **Live** app at PayPal, add the webhook to it, and fill in the three **Live** fields. Then set **Mode** to **Live**. The live app has its own Client ID, Secret and Webhook ID: the sandbox ones do not work in live mode.

## Testing on a local site

PayPal delivers webhooks only to a public `https` address, and has no tool that forwards them to your own computer the way `tcms stripe:listen` does for Stripe. A site on `example.test` needs a tunnel, such as Cloudflare Tunnel or ngrok, and the webhook at PayPal pointed at the tunnel's address.

Tunnel only what PayPal needs. A tunnel to your whole site puts its admin on the public internet. Forward only `/commerce/webhook/paypal`.

Without a tunnel you can still test: pay as usual, then run [`tcms commerce:reconcile --minutes=0`](docs/commerce/orders#orders-that-stay-pending). It asks PayPal about the order and records the payment as the webhook would have.

## Tax

PayPal cannot work out tax. An order paid with PayPal is taxed from [your own tax rates](docs/commerce/tax), and your store sends PayPal the total with the tax in it.

This holds even when **Tax Worked Out By** is set to **The payment provider, when it can**: Stripe then works out the tax for orders paid with Stripe, and your table covers orders paid with PayPal. See [Providers that cannot work out tax](docs/commerce/tax#providers-that-cannot-work-out-tax).

## Shipping addresses

Your store asks for the shipping address on its own checkout form. PayPal is not asked to collect one, and the buyer does not choose an address on PayPal's page.

## When a payment does not match

Total CMS compares what PayPal says was captured with the order's total. If the amount or the currency is different, the order is not fulfilled. It is set to **In review**. A super admin then accepts the payment on the order's page, or you refund it at PayPal and the order closes by itself. See [Orders in review](docs/commerce/orders#orders-in-review).

## Refunds and disputes

Refund from the order's page in the admin, or at PayPal. Either way PayPal tells your site, and the order shows as partly or fully refunded.

A dispute flags the order so you can respond to it at PayPal. When PayPal resolves the dispute, the order goes back to paid if it was decided for you or the buyer withdrew it, and to refunded if it was decided for the buyer.

## Currencies

PayPal takes these currencies: AUD, BRL, CAD, CHF, CNY, CZK, DKK, EUR, GBP, HKD, ILS, JPY, MXN, MYR, NOK, NZD, PHP, PLN, SEK, SGD, THB and USD. A store in any other currency is not offered PayPal. PayPal limits a few of these to accounts in their own country.

## Not supported

- **Subscriptions.** A cart with a subscription in it is not offered PayPal.
- **Payment on your own page.** The buyer always pays on PayPal's page, whatever **Checkout** is set to.

## Settings

| Setting | What it is |
|---|---|
| **Mode** | **Sandbox** takes no real money. **Live** charges real accounts. |
| **Sandbox Client ID** | From the sandbox app at developer.paypal.com. |
| **Sandbox Secret** | The secret shown beside the sandbox Client ID. |
| **Sandbox Webhook ID** | Shown when you add the webhook to the sandbox app. |
| **Live Client ID** | From the live app. |
| **Live Secret** | The secret shown beside the live Client ID. |
| **Live Webhook ID** | Shown when you add the webhook to the live app. |

Your secrets are stored on your server and sent only to PayPal. They never appear in a page, a log or an error message.

## If your Commerce add-on lapses

Buyers are no longer offered PayPal, but your store still hears PayPal's webhook. A payment already taken is still recorded, and so is its refund.
