---
title: "Digital Delivery"
description: "Sell a download kept as a file, in an S3 bucket or behind a link, chosen by the type of the Download field: the buyer gets a signed link on their order page and in their receipt, with a time limit and a download limit you set."
since: "3.7.0"
related:
  - commerce/products
  - commerce/orders
  - commerce/setup
  - commerce/events-and-extensions
---

# Digital Delivery

A digital product can carry a download. When an order is paid, the buyer is given a link to it.

## Give a product its download

1. Set the product's **Type** to **Digital**.
2. Under **Digital Delivery**, add the **Download** file.

A product has one **Download** field, and the type of that field says where the file is kept. A new store's products have a file field, so there is nothing to set up: the site keeps the file and sends it itself. To keep files somewhere else, your product schema changes the type of `download`. A property you define in your own schema replaces the one it inherits, so this is all it takes. To sell several files together, zip them.

| Type of `download` | Where the file is kept | Use it for |
|---|---|---|
| `file` (the default) | On your site | Most files. |
| `url` | Anywhere else | A file another service hosts. The product holds its address, starting with `https://`. |
| `s3file` | In your S3 bucket | A file too big to keep on the site. Needs the [S3 Uploads](docs/extensions/s3-uploads) extension. |

### A file on your site

The default. The product schema `commerce-product` already has it, and a schema of your own that inherits `commerce-product-digital` gets it too:

```json
{
  "id": "my-product",
  "type": "object",
  "inheritFrom": ["commerce-product-base", "commerce-product-digital"]
}
```

### A link

Redefine `download` as a `url` field:

```json
{
  "id": "my-product",
  "type": "object",
  "inheritFrom": [
    "commerce-product-base",
    "commerce-product-digital",
    "commerce-product-inventory",
    "commerce-product-subscription",
    "commerce-product-variants"
  ],
  "properties": {
    "download": {
      "$ref": "https://www.totalcms.co/schemas/properties/url.json",
      "field": "url",
      "label": "Download",
      "help": "The address of the file the buyer gets, starting with https://."
    }
  }
}
```

Then use that schema for your products collection.

### A file in S3

Redefine `download` as an `s3file` field, the same way as above, with the same `inheritFrom` list. Only the property changes:

```json
"download": {
  "type": "json",
  "field": "s3file",
  "label": "Download",
  "help": "The file the buyer gets, kept in your S3 bucket."
}
```

See the [S3 Uploads](docs/extensions/s3-uploads#selling-a-file-from-the-bucket) page for the whole schema, the field and the bucket it needs.

If a schema gives `download` a field type that nothing delivers, a product is sold without a download and the problem is written to the Commerce log. A product schema with no `download` property at all is a product that has none, and nothing is logged.

Whichever you choose, the buyer is given the same kind of link, and it is counted and limited the same way. See [Who else can get the file](#who-else-can-get-the-file) for how well each one is protected.

A digital product may have no download at all. A license key, a service or a membership is sold this way. See [Products with no file](#products-with-no-file).

## What the buyer gets

The moment the order is paid, each item with a file is delivered:

- **On the order page.** The page the buyer lands on after paying lists their downloads, with how many downloads are left, and the file's size when the store knows it. It appears by itself as soon as the payment is confirmed.
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

## Who else can get the file

A buyer's signed link is the only thing a buyer needs. It works for a guest, and it asks for no account and no group.

Everyone else is kept out the way Total CMS protects any file: by the **File Access Groups** of the collection it is in.

### A file on your site

Total CMS can serve any file field at an address such as `/download/products/guide/download`. For a file in a collection with File Access Groups, that address works only for a signed-in member of one of those groups, and for a super admin.

A new store's products collection is made with the group `admin` there, so a product's file is closed to the public from the first upload. Check yours in **Collections → Products → File Access Groups**:

- **Keep at least one group there.** With none, the address above gives the file to anyone, and a product's ID is in your shop's URLs.
- **A collection you made yourself has no group until you add one.** The store only sets the group on the collection it creates. If your products live in a collection you built on your own product schema, add a group there. The Store widget on the admin dashboard warns you while a products collection with a file download has none.
- **Use a staff group.** A group your customers hold, such as one a membership grants, lets every customer in it fetch every product's file. The group `default` means any signed-in user at all, and on a store where customers sign up themselves that is anyone. The Store widget warns you while the products collection's groups include `default`, as it does when they are empty.

Staff in one of the groups can use the download button on the product's form.

### A file in S3

The same rule covers the extension's own addresses for the file: they follow the collection's File Access Groups.

Use a **private** bucket. The buyer is sent to a signed link to the bucket that works for a short time. In a public bucket, the file can also be read at its public address by anyone who has that address.

### A link

The store's limits end at its own link. Once a download is counted, the buyer is sent to the address you entered, and that address is as open as the service that hosts it makes it. Use this for files that are not worth guarding, or with a host that protects them itself.

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
        ({% if download.size %}{{ download.size|filesize }}, {% endif %}{{ download.remaining }} left)
    {% else %}
        {{ download.fileName|e }} (no downloads left)
    {% endif %}
{% endfor %}
```

| Key | What it is |
|---|---|
| `title` | The product's name on the order. |
| `fileName` | The name the file downloads under. |
| `size` | The file's size in bytes. `0` when the store does not know it, as for a link. |
| `remaining` | How many downloads are left. |
| `url` | The signed link. Empty when no downloads are left. |
| `lineId` | The order line it belongs to. |

`cms.commerce.downloads()` gives links only for an order that `cms.commerce.order()` or `cms.commerce.orders()` handed you. An order id a visitor typed gets nothing.

In a mailer template, pass it `data`, the order the email is about.

## Other places to keep a download

An extension can add a place of its own to keep downloads, as S3 Uploads does. See [Add a place to keep downloads](docs/commerce/events-and-extensions#add-a-place-to-keep-downloads).

## If your Commerce add-on lapses

Download links keep working. What a buyer paid for stays theirs.
