---
title: "Stripe Payments (Bundled Extension)"
description: "Take payments in a Total CMS store with Stripe Checkout, on Stripe's page or on your own. Covers keys, the webhook endpoint, test mode and what happens when a payment goes wrong."
since: "3.7.0"
---

# Stripe Payments

The Stripe extension lets a Total CMS store take card payments. The buyer pays on Stripe's own checkout page, or in Stripe's form shown [on your own page](#taking-payment-on-your-own-page). Either way, card numbers never touch your site.

It needs the **Pro edition** and the **Commerce add-on**. A development license and a running trial include both.

## How a payment works

1. The buyer checks out. Total CMS builds the order from your catalog and works out the total. Nothing the browser sends can change a price.
2. Total CMS asks Stripe for a checkout page for exactly that total, and sends the buyer to it.
3. The buyer pays, and Stripe sends them back to your site.
4. Stripe tells your site the payment succeeded, with a signed message called a webhook. **Only then** is the order marked paid.

Step 4 is the one that counts. Coming back to your site in step 3 proves nothing, because anyone can open that address. So the order page says "Confirming your payment…" until Stripe's webhook arrives, usually within a second or two.

## Setup

### 1. Enable the extension

Stripe ships with Total CMS as a bundled extension, disabled by default. Enable it in **Admin → Extensions**, or from the command line:

```bash
tcms extension:enable totalcms/stripe
```

### 2. Add your test keys

Open the extension's settings from **Admin → Extensions**. Leave **Mode** on **Test** and paste your **Test Secret Key**. It starts with `sk_test_` and is in the Stripe dashboard under **Developers → API keys**, with test mode on.

### 3. Add the webhook endpoint

In the Stripe dashboard, add a webhook endpoint that points at your site:

```text
https://example.com/commerce/webhook/stripe
```

If Total CMS runs in a subfolder, put the folder first, such as `https://example.com/cms/commerce/webhook/stripe`.

Send these events to it:

| Event | What Total CMS does with it |
|---|---|
| `checkout.session.completed` | Marks the order paid |
| `checkout.session.async_payment_succeeded` | Marks the order paid, for a bank payment that clears later |
| `checkout.session.async_payment_failed` | Marks the payment failed |
| `checkout.session.expired` | Cancels an order nobody paid for |
| `charge.refunded` | Records a refund you made in the Stripe dashboard |
| `charge.dispute.created` | Flags the order as disputed |
| `charge.dispute.closed` | Records whether you won or lost the dispute |

Stripe then shows a **signing secret** that starts with `whsec_`. Paste it into **Test Webhook Signing Secret**. Every webhook is checked against it, and one without a good signature is refused.

### 4. Switch Stripe on in your store

Open **Settings → Commerce** and choose **Stripe** under **Payment Providers**. Stripe is offered to buyers once it is chosen there and both test values are filled in.

### 5. Make a test purchase

Add a product to your cart, check out, and pay with one of [Stripe's test cards](https://docs.stripe.com/testing), such as `4242 4242 4242 4242`. The order page should change from "Confirming your payment…" to "Payment received".

If it stays on "Confirming your payment…", the webhook is not reaching your site. Check the endpoint's address and its delivery log in the Stripe dashboard.

### 6. Go live

Add your **Live Secret Key** and a live webhook endpoint with its own **Live Webhook Signing Secret**, then set **Mode** to **Live**. If you take payment on your own page, add the **Live Publishable Key** too. The live endpoint has a different signing secret from the test one.

A key that does not match the mode is ignored. With a live secret key in test mode, or a test secret key in live mode, Stripe is not offered to buyers. This stops a store that says "test" from charging real cards.

## Taking payment on your own page

By default the buyer leaves your site to pay on Stripe's page and comes back. Stripe can show the same form inside your own page instead, so the buyer never leaves.

1. In the extension's settings, add your **Test Publishable Key**. It starts with `pk_test_` and sits beside the secret key in the Stripe dashboard.
2. In **Settings → Commerce**, set **Checkout** to **On this site**.

The buyer now pays on a page of your site. Total CMS has a built-in one. To use your own design, make a Site Builder page, choose it under **Payment Page**, and print the form where you want it:

```twig
<h1>Order {{ order.number }}</h1>
{{ cms.commerce.embed(order, token) }}
{{ cms.assetsBody() }}
```

The page is given `order` and `token`. The form needs `cms.assetsBody()` on the page, which loads the script that mounts it.

Three things to know:

- **Stripe needs the publishable key for this.** With **Checkout** set to **On this site** and no publishable key for the mode in use, the buyer pays on Stripe's own page, as if **Checkout** were set to **Provider's page**. The same happens with a key of the wrong mode, such as a live key in test mode.
- **The form is still Stripe's.** It is drawn by Stripe inside a frame from `js.stripe.com`. Your page holds the publishable key and a secret for that one checkout. Your secret key and webhook secret stay on your server.
- **The webhook still decides.** The order is marked paid when Stripe's webhook arrives, exactly as with Stripe's own page.

### If your site sends a Content-Security-Policy

Total CMS does not limit where scripts come from. If your server or a template adds a `Content-Security-Policy` header that does, allow Stripe in it:

| Directive | Hosts |
|---|---|
| `script-src` | `https://js.stripe.com` `https://*.js.stripe.com` |
| `frame-src` | `https://js.stripe.com` `https://*.js.stripe.com` `https://hooks.stripe.com` `https://checkout.stripe.com` |
| `connect-src` | `https://api.stripe.com` `https://checkout.stripe.com` |
| `img-src` | `https://*.stripe.com` |

## Working on a local site

Stripe cannot reach a site on your own computer, so webhooks need forwarding. The [Stripe CLI](https://docs.stripe.com/stripe-cli) does this:

```bash
stripe listen --forward-to https://example.test/commerce/webhook/stripe
```

It prints a signing secret for the session. Use that as your **Test Webhook Signing Secret** while it runs.

## When a payment does not match

Total CMS compares what Stripe says was paid with the order's total. If the amount or the currency is different, the order is not fulfilled. It is set to **review**, and Total CMS logs a warning. You decide whether to refund it or honor it.

The same happens when a buyer pays for a checkout the store had already given up on, for example an old payment page left open in another tab.

## Refunds and disputes

Refund a payment in the Stripe dashboard, and Stripe tells your site. The order shows as partly or fully refunded, and the refund is added to the order's transactions.

A dispute flags the order so you can respond to it at Stripe. When Stripe closes the dispute, the order goes back to paid if you won, or to refunded if you lost.

## Settings

| Setting | What it is |
|---|---|
| **Mode** | `Test` takes no real money. `Live` charges real cards. |
| **Test Publishable Key** | Starts with `pk_test_`. Needed only to take payment on your own page. |
| **Test Secret Key** | Starts with `sk_test_`. |
| **Test Webhook Signing Secret** | Starts with `whsec_`. From the test webhook endpoint. |
| **Live Publishable Key** | Starts with `pk_live_`. Needed only to take payment on your own page. |
| **Live Secret Key** | Starts with `sk_live_`. A restricted key (`rk_live_`) works if it may write Checkout Sessions and Refunds. |
| **Live Webhook Signing Secret** | Starts with `whsec_`. From the live webhook endpoint. |
| **Statement Descriptor Suffix** | Optional. Up to 22 characters added after your Stripe account's name on the buyer's card statement. |

Your secret keys and webhook secrets are stored on your server and sent only to Stripe. They never appear in a page, a log or an error message. A publishable key is made to be seen: it appears in the page that shows Stripe's form, and can do nothing but show it.

## If your Commerce add-on lapses

Your store stops offering checkout, but Stripe's webhooks are still heard. A payment that was already in progress is still recorded, and so is a later refund or dispute of an old order.
