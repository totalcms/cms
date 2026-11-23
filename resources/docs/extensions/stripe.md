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

Open the extension's settings from **Admin → Extensions**. Leave **Mode** on **Test** and paste your **Test Secret Key**. It starts with `sk_test_` and is in the Stripe dashboard under **Developers → API keys**.

Test keys come from a Stripe **sandbox**, which you open from the account menu at the top of the dashboard. Some accounts have a **Test mode** switch in its place. Either way the keys start with `sk_test_` and `pk_test_`, and nothing you do with them touches your live account or its payments.

### 3. Add the webhook endpoint

In the Stripe dashboard, add a webhook endpoint that points at your site:

```text
https://example.com/commerce/webhook/stripe
```

If Total CMS runs in a subfolder, put the folder first, such as `https://example.com/cms/commerce/webhook/stripe`.

When Stripe asks which **API version** the endpoint uses, choose the latest one. Stripe writes each event in the endpoint's version, and its default is your account's own, which on an older account can be years behind what Total CMS reads. The choice belongs to this endpoint only: it changes nothing else on your account.

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

Open **Settings → Commerce** and tick **Stripe** under **Payment Providers**. Stripe is offered to buyers once it is ticked there and both test values are filled in.

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

## Testing on a local site

Stripe tells your store that a payment went through by calling its webhook address. It cannot call a site on your own computer, such as one at a `.test` address or on `localhost`. Stripe's own command line tool forwards the events to your site instead, and `tcms stripe:listen` runs that tool for you.

### What you need

- The [Stripe CLI](https://docs.stripe.com/stripe-cli), installed.
- The extension enabled, in **Test** mode, with a **Test Secret Key**: steps 1 and 2 of [Setup](#setup).
- **Stripe** ticked under **Payment Providers**: step 4.

Skip step 3. A local site needs no webhook endpoint in the Stripe dashboard, and you paste no signing secret.

### Start listening

Give the command the address you open the site at, and leave it running:

```bash
tcms stripe:listen --url=https://example.test
```

It does three things:

1. **It starts the Stripe CLI with your site's own test key.** The CLI then hears the same Stripe account the site charges, and not whichever account `stripe login` last chose.
2. **It saves the secret the Stripe CLI signs with** as your **Test Webhook Signing Secret**. Your site believes only events signed with it.
3. **It forwards every event to your site** until you stop it with Ctrl-C. Events arrive in Stripe's current API version, whatever your account's own is.

It works with test keys only, and it never shows a key or a secret.

| Option | What it does |
|---|---|
| `--url=…` | The address of your site, with its folder if Total CMS runs in one, such as `https://example.test/cms`. Without it, the command uses the address the site was last opened at in a browser. A site on `localhost` or an IP address always needs it, and so does any site just after its cache was cleared. |
| `--check` | Sets everything up and reports, without forwarding anything. |
| `--skip-verify` | Does not check the site's certificate. Use it when the Stripe CLI reports a certificate error for your local address. |

### Make a test purchase

1. Add a product to your cart and check out with an email address you can read.
2. Pay with the test card `4242 4242 4242 4242`, any future expiry date, any CVC and any postal code.

When it works:

- **The order page** changes from "Confirming your payment…" to "Payment received", a second or two after you pay.
- **The terminal** shows a line for each event, each ending in `[200]`. The store acts on `checkout.session.completed` and answers the others without using them.
- **The order** is under **Orders** in the admin as **Paid**.
- **The receipt** is sent the next time the job runner runs. On a local site, run `tcms jobs:process` to send it. See [Receipts](docs/commerce/setup#receipts).

To test payment on your own page, set **Checkout** to **On this site** and buy again. See [Taking payment on your own page](#taking-payment-on-your-own-page).

### If something goes wrong

| What you see | What to do |
|---|---|
| The order page stays on "Confirming your payment…" and the terminal shows no event | The command is not running, or it was started before you changed the Test Secret Key. Start it again. |
| Event lines end in `[400]` | The signature did not match. Stop the command and start it again: it saves the right secret each time it starts. |
| The Stripe CLI reports a certificate error | Add `--skip-verify`. |
| "The command line does not know this site's address" | Add `--url` with the address you open the site at. This happens on `localhost`, and on any site after `tcms cache:clear` until it is opened in a browser again. |
| "The Stripe extension is in Live mode" | The command is for test payments. Set **Mode** to **Test**. |
| `tcms` says there are no commands in the `stripe` namespace | The extension is not enabled, or **CLI Commands** is switched off for it under **Admin → Extensions**. |
| The order is paid and no receipt arrives | Run `tcms jobs:process`, and check that the site's email settings are filled in. |

## When a payment does not match

Total CMS compares what Stripe says was paid with the order's total. If the amount or the currency is different, the order is not fulfilled. It is set to **In review**, and Total CMS logs a warning. A super admin then accepts the payment on the order's page, or you refund it at Stripe and the order closes by itself. See [Orders in review](docs/commerce/orders#orders-in-review).

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
