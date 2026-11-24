---
title: "Digital Delivery"
description: "Sell a file: the buyer gets a signed download link on their order page and in their receipt, with a time limit and a download limit you set."
since: "3.7.0"
related:
  - commerce/products
  - commerce/orders
  - commerce/setup
  - commerce/events-and-extensions
---

# Digital Delivery

A digital product can carry a file. When an order is paid, the buyer is given a link to that file. Nobody else can get it.

## Give a product its file

1. Set the product's **Type** to **Digital**.
2. Under **Digital Delivery**, upload the file to **Download**.

A product has one file. To sell several files together, zip them and upload the zip.

A digital product may have no file at all. A license key, a service or a membership is sold this way. See [Products with no file](#products-with-no-file).

## What the buyer gets

The moment the order is paid, each item with a file is delivered:

- **On the order page.** The page the buyer lands on after paying lists their downloads, with the file's size and how many downloads are left. It appears by itself as soon as the payment is confirmed.
- **In the receipt.** The receipt email lists the same links.
- **In their account.** A signed-in customer's orders carry their downloads too.

The order is marked **Fulfilled** for that item. An order of only downloads is **Completed** at once.

## Links run out, downloads are limited

Two settings in **Settings → Commerce** protect a file from being passed around:

| Setting | What it does | Default |
|---|---|---|
| **Download Link Lifetime (hours)** | How long one link works after it is made. | 72 |
| **Downloads per Purchase** | How many times the buyer may download each item they bought. | 5 |

A link that has run out is not the end of it. The links on the order page are made fresh every time the page is opened, so a buyer opens their order again, from the link in their receipt, and downloads from there. The link to the order page itself works for 30 days.

The download limit does run out for good. A buyer who has used all their downloads is told so, and to reply to their receipt. You can then send the file yourself.

The admin's page for the order shows how many times each item has been downloaded.

## When the file is taken away

- **A refund of the whole order** ends the buyer's downloads. A part refund does not.
- **A dispute** stops them until it is settled.
- **Replace the file** on the product and every buyer, past and future, gets the new one from their links. Their download count carries on from where it was.
- **Remove the file** and a buyer who follows a link is told the file is not available.

## The file is served nowhere else

Total CMS can serve any file field at an address such as `/download/products/guide/download`. A product's **Download** is the exception: those addresses answer "not found" for it, for everyone. The only way to the file is a buyer's signed link.

That includes you. The download button on the product's own form in the admin does not work for this field. To check a file as a buyer would, buy the product with a 100% discount code.

Do not put a paid file in any other field of the product, or in another collection. Only **Download** is guarded.

## Products with no file

A digital item with no file is not delivered by the store. It stays **Unfulfilled** until something says it is done:

- an extension, such as one that issues a license key, by calling `OrderFulfillment::markLineFulfilled()`. See [Events and Extensions](docs/commerce/events-and-extensions).
- you, with **Mark as sent** on the order's page.

Fifteen minutes after payment, an order with a digital item still unfulfilled is flagged **Not delivered**, so nothing sold is forgotten.

If you add the file to the product later, choose **Run fulfillment again** on the order and the item is delivered.

## In your own templates

The order page and the receipt already show the downloads. On a page of your own, each order a buyer is given carries them:

```twig
{% set order = cms.commerce.order(orderId, token) %}
{% for download in cms.commerce.downloads(order) %}
    {% if download.url %}
        <a href="{{ download.url|e('html_attr') }}">{{ download.fileName|e }}</a>
        ({{ download.size|filesize }}, {{ download.remaining }} left)
    {% else %}
        {{ download.fileName|e }} (no downloads left)
    {% endif %}
{% endfor %}
```

| Key | What it is |
|---|---|
| `title` | The product's name on the order. |
| `fileName` | The name the file downloads under. |
| `size` | The file's size in bytes. |
| `remaining` | How many downloads are left. |
| `url` | The signed link. Empty when no downloads are left. |
| `lineId` | The order line it belongs to. |

`cms.commerce.downloads()` gives links only for an order that `cms.commerce.order()` or `cms.commerce.orders()` handed you. An order id a visitor typed gets nothing.

In a mailer template, pass it `data`, the order the email is about.

## If your Commerce add-on lapses

Download links keep working. What a buyer paid for stays theirs.
