---
title: "Subscriptions"
description: "Sell something a buyer pays for every week, month or year. Stripe bills each period, and every payment is an order of its own with a receipt."
since: "3.7.0"
related:
  - commerce/memberships
  - commerce/products
  - commerce/orders
  - extensions/stripe
  - commerce/events-and-extensions
---

# Subscriptions

A subscription is a product the buyer pays for again every period, until they cancel. Use one for a membership, a service, a newsletter, a software plan, or anything else that does not ship.

## What you need

- A payment provider that can bill subscriptions. The bundled [Stripe](docs/extensions/stripe#subscriptions) extension can. PayPal has no subscription support at all, so a cart with a subscription in it is offered Stripe only. When no provider you have turned on can bill a subscription, the cart refuses it with `subscription_unavailable`.
- Three more events on your Stripe webhook endpoint. See [Stripe: Subscriptions](docs/extensions/stripe#subscriptions).

## Make a product a subscription

Open the product and go to **Subscription and Membership**.

| Field | What to set |
|---|---|
| **Billing** | **Subscription** |
| **Every** | `Day`, `Week`, `Month` or `Year` |
| **Interval Count** | How many of those make one period. `1` with `Month` is monthly. `3` with `Month` is every three months. |

The product's **Price** is what one period costs.

A subscription cannot ship. A product billed as a **Subscription** that has **Requires Shipping** ticked cannot be bought: a provider bills a period, it does not send a parcel each time.

A variant can have its own period. For example, a plan with a `Monthly` variant and a `Yearly` variant, each with its own price.

These fields come from the `commerce-product-subscription` part. A product schema without it is always bought once. See [Product parts](docs/commerce/products#product-parts).

## One time and monthly on one product

A variant has its own **Billing** field. Leave it empty and the variant is billed as its product is ("Same as the product"). Choose **One time** or **Subscription** and the variant is billed that way, whatever the product says.

So one product can offer a one-time option and a monthly option. This is how a donation is both a single gift and a monthly one. See [Name Your Price](docs/commerce/name-your-price#walk-through-a-donation-product).

- A subscription variant uses its own **Billed Every** and **Interval Count** when you set them, and the product's when you do not.
- The price still comes from the variant, or from the product when the variant has none. On a [name-your-price](docs/commerce/name-your-price) product, the buyer types it.
- A one-time variant of a product that grants an access group still grants that group, and for good. The group is set on the product, not the variant, and a one-time purchase is kept unless it is refunded. See [Memberships](docs/commerce/memberships#a-one-time-variant-of-a-membership-product).

### Monthly amounts a buyer names

When the buyer names the amount of a subscription, the payment provider must bill from the amount it is sent, not from a plan made ahead of time. Stripe does. If no provider you have turned on can, the cart refuses the line with `subscription_unavailable`. See [Name Your Price](docs/commerce/name-your-price#monthly-amounts).

## What the buyer sees

- **In the cart**, a subscription's price reads "every month".
- **At checkout**, the totals show two amounts: **Due today**, and **Then every month**.
- **After paying**, the order page and the receipt link to **Manage your subscription**.

A cart can hold a subscription and one-time products together. The one-time products are paid for with the first payment.

One checkout makes one subscription. So every subscription in a cart must have the same period: a monthly plan and a yearly plan cannot be bought together. The buyer is told, and can buy them one after the other.

## How billing works

Stripe holds the schedule. Total CMS never charges a card, and no scheduled task on your server bills anyone.

1. The buyer pays the first period at checkout. Stripe tells your site, the order is marked paid, and the subscription becomes **Active**.
2. Each period, Stripe charges the card and tells your site. Total CMS makes a **new order** for that payment, marks it paid, sends a receipt and tells your listeners. In the admin it carries a **Renewal** label.
3. If a charge fails, Stripe tries the card again over the following days. The subscription shows **Payment due**, and the subscriber is sent one email asking them to update their card.
4. If every retry fails, Stripe ends the subscription, and it shows **Canceled**.

Because each period is an order, you refund a renewal the way you refund any order: from the order's page.

### A subscriber keeps their signup price

A subscription's lines and amounts are fixed when it starts. If you change the product's price later, new subscribers pay the new price and existing subscribers go on paying what they signed up for. Every renewal is checked against that amount. A payment for any other amount is held: its order shows **Payment mismatch**, nothing is delivered for it, and a super admin decides whether to accept or refund it.

### Statuses

| Status | What it means | Does the subscriber keep access? |
|---|---|---|
| **Starting** | The first payment is not confirmed yet. | No |
| **Active** | Paid up. | Yes |
| **Payment due** | The last charge failed. Stripe is retrying. | Yes |
| **Paused** | Paused at the provider. | No |
| **Canceled** | Ended. Nothing more is charged. | No |

**Ends with period** is an active subscription whose subscriber has canceled. They keep what they paid for until the period ends.

## Discount codes

A code's **On a Subscription** setting decides how long it lasts:

- **First payment only**: the first period is cheaper, and every later one is full price.
- **Every payment**: every period is cheaper, for as long as the subscription runs.

A renewal never counts as another use of the code.

## Tax

Tax on a subscription follows your store's tax setting, like any order.

- With your own table of rates, the tax for one period is worked out at checkout and charged every period.
- With Stripe Tax, Stripe works out the tax on each payment. The subscription's amount is shown **before tax**.

## The subscriber's own page

Every subscription has a page at `/commerce/subscription/{id}`. It shows what they pay and when, the payments so far, and two buttons:

- **Update your card** opens Stripe's own billing page.
- **Cancel subscription** asks Stripe to cancel at the end of the period already paid for.

The page opens for the signed-in customer the subscription belongs to, and for anyone holding its signed link. That link is in the receipt and in the failed-payment email, so a guest can manage a subscription too.

Canceling does not change anything in your store by itself. It asks Stripe, and the subscription changes when Stripe confirms, usually within seconds. This is the same rule as for payments: the store changes when the provider says so.

A subscriber can still open this page and cancel if your Commerce add-on has lapsed. Someone who is still being charged can always stop it.

### In your own templates

```twig
{# On an account page: the signed-in customer's subscriptions #}
{% for subscription in cms.commerce.subscriptions() %}
    <p>
        {{ (subscription.lines|first).title|e }}:
        {{ subscription.recurringTotal|money(subscription.currency) }} every {{ subscription.interval|e }}
        ({{ subscription.status|e }})
        <a href="{{ subscription.url|e('html_attr') }}">Manage</a>
    </p>
{% else %}
    <p>You have no subscriptions.</p>
{% endfor %}
```

See [Twig: Commerce](docs/twig/commerce) for `subscriptions()`, `subscription()` and `subscriptionUrl()`.

## Subscriptions in the admin

Open **Orders**, then **Subscriptions**. The list shows each subscriber, their plan, the amount, the status and the next payment. Above it is how many are running and what they are worth a month.

Open one to see its payments. A super admin has three buttons:

| Button | What it does |
|---|---|
| **Check with Stripe** | Asks Stripe where the subscription stands, and puts your record and the subscriber's access right. Use it when something looks out of date. |
| **Cancel at end of period** | The subscriber keeps what they paid for and is not charged again. |
| **Cancel now** | Ends it at once. Nothing is refunded: refund a payment on its order. |

A subscription that needs someone says why at the top of its page, and counts toward the red number on **Orders** in the sidebar. Once you have dealt with it, anyone who can see orders can choose **Mark as handled** there. That takes it off the count and changes nothing else: nothing is asked of Stripe and its status stays as it is.

## The failed-payment email

`tcms commerce:install` adds a mailer template called `commerce-payment-failed`. It is sent once for each invoice that fails, however many times Stripe retries it. Edit it under **Mailer**. In the template the subscription is `data`.

Turn **off** Stripe's own failed-payment emails, or your subscribers get two.

## What is not here yet

- **Free trials.** The Free Trial Days field has no effect yet.
- **Changing plan.** To move a subscriber to another plan, cancel the old subscription and have them buy the new one.
- **Subscriptions with PayPal.**
- **Subscriptions that ship**, such as a monthly box.
