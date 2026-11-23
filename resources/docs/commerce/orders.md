---
title: "Orders"
description: "Work with your store's orders in the Total CMS admin: find an order, mark items as sent, refund a payment, and deal with the orders that need attention."
since: "3.7.0"
related:
  - commerce/setup
  - commerce/events-and-extensions
  - extensions/stripe
---

# Orders

**Orders** is in the admin sidebar on a site with Commerce, for anyone [allowed to see orders](docs/commerce/setup#5-let-your-team-see-orders). A red number on its icon counts the orders that need someone.

## Find an order

The list shows the newest orders first: the number, the day, the buyer's email, the total, and where the payment and the fulfillment stand.

Filter it by payment, by fulfillment, by date, or to only the orders that need attention. Search takes an order number, with or without its `#`, or part of an email address.

The address bar keeps your filters, so a filtered list can be bookmarked.

## What the statuses mean

**Payment**

| Status | What it means |
|---|---|
| **Pending** | The buyer started to check out and has not paid. |
| **Paid** | The payment provider confirmed the payment. |
| **Failed** | The payment did not go through. |
| **Expired** | Nobody paid, and the checkout was given up or cancelled. |
| **In review** | Money arrived that the store could not match to the order. Nothing was sent. See [Orders in review](#orders-in-review). |
| **Part refunded** | Some of the money went back. |
| **Refunded** | All of the money went back. |
| **Disputed** | The buyer disputed the payment with their bank. |

**Fulfillment**

| Status | What it means |
|---|---|
| **Unfulfilled** | Nothing has been sent. |
| **Part fulfilled** | Some items have been sent. |
| **Fulfilled** | Everything has been sent. |
| **Nothing to send** | The order has nothing that needs delivering. |

## Orders that need attention

An order is flagged when something went wrong that only a person can settle. Its page says what, and what to do.

| Flag | What happened |
|---|---|
| **Payment mismatch** | The amount or currency paid is not the order's total. Nothing was sent. See [Orders in review](#orders-in-review). |
| **Paid after expiry** | The buyer paid a checkout the store had already given up on. Nothing was sent. See [Orders in review](#orders-in-review). |
| **Wrong provider** | The order was paid through a payment provider other than the one it was started with. Nothing was sent. See [Orders in review](#orders-in-review). |
| **Oversold** | More was sold than was in stock. |
| **Refund failed** | The payment provider refused a refund you asked for. No money moved. |
| **Disputed** | Answer the dispute at the payment provider. |
| **Not delivered** | The order was paid more than 15 minutes ago, and a digital item in it has still not been delivered. |

### Orders in review

The payment provider says it was paid, but what it says does not match the order. So the store holds the order: the buyer gets no receipt, nothing is delivered, and stock is not counted. The money is at the payment provider the whole time.

You settle it one of two ways.

- **Accept the payment.** On the order's page, a super admin chooses **Accept this payment**. The order becomes **Paid**, the buyer gets their receipt, and what they bought is delivered, as if the payment had matched. The page says how much was paid and what the order's total is. **Payments** keeps a line with who accepted it.
- **Refund it at the payment provider.** When all of the money has gone back, the order closes by itself: it shows **Refunded** and is cancelled.

If the buyer paid too much, refund the difference at the payment provider first, then accept the rest. If they paid too little and you accept it, the order can later be refunded up to what was paid, not up to its total.

A payment made in another currency can be accepted, but it cannot be refunded from the order's page afterward. Refund it at the payment provider.

## One order

An order's page shows the items as they were sold, the totals, the buyer and their address, and **Payments**: every movement of the order's money, oldest first. That list is written by the store as the payment provider reports. Nobody can edit it.

### Mark items as sent

Tick the items you are sending, add a tracking number and link if you have them, and choose **Mark as sent**. You can send an order in several parcels.

When the last item is marked, the order becomes **Fulfilled**.

Only a paid order can be marked as sent.

### Keep a note

**Notes** are for your team. The buyer never sees them.

### Send the receipt again

**Send the receipt again** emails the receipt to the address on the order.

### Refund

**Refund** asks the payment provider to send money back. Choose the amount, up to what is left on the order, and give a reason if you like.

The order does not change at once. It shows **Part refunded** or **Refunded** when the provider confirms the money has gone back, usually within seconds. Until then the refund is listed under **Payments** as requested.

A refund made in the provider's own dashboard shows on the order the same way.

Refunds are for super admins.

### Cancel

**Cancel this order** is for an order nobody has paid for. A paid order is refunded, not cancelled.

### Run fulfillment again

If your site delivers something automatically when an order is paid, such as a license, and that failed, **Run fulfillment again** tells your code about the order a second time. See [Events and Extensions](docs/commerce/events-and-extensions).

## Who can do what

| | A team member with **Commerce Orders** | A super admin |
|---|---|---|
| See orders | Yes | Yes |
| Mark items as sent, keep notes, resend a receipt | Yes | Yes |
| Refund | No | Yes |
| Cancel an unpaid order | No | Yes |
| Accept a payment in review | No | Yes |
| Run fulfillment again | No | Yes |

## On the dashboard

The admin home page shows your store to anyone who can see orders: what it took in the last 30 days less refunds, how many orders need attention, the newest orders, and the products with 5 or fewer left.

## Orders cannot be edited by hand

Orders and their payments are written only by the store. No one can change one through the collections admin, the API, an import or the MCP server, a super admin included. This is what lets you trust that an order marked paid was paid.

To correct an order, use the actions on its page.
