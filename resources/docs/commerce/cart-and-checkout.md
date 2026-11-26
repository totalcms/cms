---
title: "Cart and Checkout"
description: "Put an add-to-cart button, a cart and a checkout form on your Total CMS site, and choose whether buyers pay on the provider's page or on yours."
since: "3.7.0"
related:
  - twig/commerce
  - commerce/discounts
  - extensions/stripe
---

# Cart and Checkout

Every part of the storefront is one line of Twig. Each prints plain HTML that you style, and each works without JavaScript.

## The pieces

```twig
{# On a product page #}
{{ cms.cart.addButton(product.id) }}

{# For a product with variants: a choice, a quantity and a button #}
{{ cms.cart.variantPicker(product.id) }}

{# In your header: a button with the count, and the cart it opens #}
{{ cms.cart.toggle() }}
{{ cms.cart.drawer() }}

{# On your cart page #}
{{ cms.cart.page() }}
{{ cms.cart.checkoutForm() }}
```

Your layout must print the site's assets, which most layouts already do:

```twig
{{ cms.assetsHead() }}
...
{{ cms.assetsBody() }}
```

On a site with Commerce these include the cart's own small script and stylesheet. With them, adding to the cart updates the page in place.

## The slide-out cart

`cms.cart.drawer()` prints a cart that slides in from the side. Put it once on the page, usually in your layout, and put `cms.cart.toggle()` wherever the cart button belongs.

```twig
{{ cms.cart.toggle({label: 'Bag'}) }}
{{ cms.cart.drawer({side: 'left', title: 'Your bag', checkoutUrl: '/cart'}) }}
```

Point `checkoutUrl` at the page that holds your checkout form. In this example that is the cart page.

```twig
{# On /cart #}
{{ cms.cart.page() }}
{{ cms.cart.checkoutForm() }}
```

| Option | Default | What it does |
|---|---|---|
| `side` | `right` | Which edge it slides from: `right` or `left`. |
| `title` | `Your cart` | The heading. |
| `checkoutUrl` | `/checkout` | Where the Checkout button goes: the page that holds your checkout form. |
| `openOnAdd` | `true` | Open the cart when something is added to it. |
| `class` | | A class of your own on the cart. |

It opens when the buyer adds a product, and closes with its Close button, a click outside it, or the Esc key.

### Styling it

The cart takes its colors from your page. To set them, give it any of these CSS properties:

```css
.cart-drawer {
	--cart-drawer-width: 26rem;
	--cart-drawer-bg: #fffdf8;
	--cart-drawer-color: #1c1c1c;
	--cart-drawer-accent: #b4442c;      /* the Checkout button and the count */
	--cart-drawer-on-accent: #fff;      /* the text on them */
	--cart-drawer-radius: 0.75rem;
}
```

It sets no font, so it uses your site's.

## The mini cart and the cart page

`cms.cart.widget()` prints a small cart you can leave on every page. `cms.cart.page()` prints the full cart, with a quantity and a remove button on every line, and a box for a [discount code](docs/commerce/discounts).

Each reloads itself when the cart changes. You do not have to wire them to the add button.

To build a cart of your own, read it yourself:

```twig
{% for line in cms.cart.lines() %}
	<p>{{ line.qty }} × {{ line.title }}: {{ line.lineTotal|money }}</p>
{% endfor %}
<p>Subtotal: {{ cms.cart.subtotal()|money }}</p>
```

### Styling the cart page and the checkout form

The page they are on is your own Site Builder page. You decide what is on it and where: the cart beside the checkout form, or one above the other.

What `cms.cart.page()` and `cms.cart.checkoutForm()` print comes with a plain layout. The cart is a table that turns into stacked lines where it has little room. The form puts each field on a row, and pairs the short ones (city with region, postal code with country) where there is room. Both use your site's font and text color.

To match your design, set any of these on the page, or on whatever the cart and the form are inside:

```css
.shop {
	--commerce-accent: #b4442c;       /* the pay button */
	--commerce-on-accent: #fff;       /* the text on it */
	--commerce-border: #d9d2c5;       /* field and table lines */
	--commerce-radius: 0.75rem;       /* fields and buttons */
	--commerce-field-bg: #fff;        /* inside a field */
}
```

For anything more, write your own CSS for their classes, such as `.commerce-cart-table`, `.commerce-checkout-field` and `.commerce-checkout-submit`. Every built-in rule is one class deep, so a rule of yours for the same thing wins without `!important`.

## Checkout

`cms.cart.checkoutForm()` prints the form that starts a checkout: the buyer's email, a shipping address if anything in the cart ships, what the order comes to, and how they pay. It prints nothing for an empty cart, or when nobody can pay yet.

### What the order comes to

The form lists the goods, any discount, [shipping](docs/commerce/shipping), [tax](docs/commerce/tax) and the total. Shipping and tax depend on where the order is going, so the form works them out again each time the buyer changes their address or how they pay.

A figure the store does not have yet is said in words, not shown as zero: shipping before there is an address, and tax that the payment provider works out when the buyer pays.

Your own script can ask for the same numbers. Post the form's fields to `/commerce/checkout/quote` with `Accept: application/json`. It makes no order.

When the buyer sends it, Total CMS:

1. reads every price from your catalog, and checks stock and the discount code again;
2. builds the order and its total;
3. asks the payment provider to collect exactly that total.

The buyer is then sent to pay.

### After paying

The buyer comes back to the order's page. It says "Confirming your payment…" until the provider's webhook arrives, usually within a second or two, then shows the order as paid.

The address of that page carries a signed link. Without it, nobody can open the order.

To use your own design, make a Site Builder page and choose it under **Order Page** in **Settings → Commerce**. The page is given `order`:

```twig
<h1>Thank you. Order #{{ order.number }}</h1>
{% for line in order.lines %}
	<p>{{ line.qty }} × {{ line.title }}</p>
{% endfor %}
<p>Total: {{ order.grandTotal|money(order.currency) }}</p>
```

### Paying on your own page

Set **Checkout** to **On this site** in **Settings → Commerce**, and the provider's form is shown inside a page of your site. The buyer never leaves.

The provider must support it. If it does not, that checkout uses the provider's own page. Stripe supports it once it has [its publishable key](docs/extensions/stripe#taking-payment-on-your-own-page), and uses its own page until then.

Total CMS has a built-in page for the form. To use your own, make a Site Builder page, choose it under **Payment Page**, and print the form where you want it:

```twig
<h1>Order #{{ order.number }}</h1>
{{ cms.commerce.embed(order, token) }}
```

The form is drawn by the provider, in a frame of its own. Your page never holds a card number.

## Carts and accounts

A visitor's cart is kept with their session. When a customer signs in, their cart is saved to their account, so it is there the next time they sign in, on any device.

Set **Customer Accounts Collection** in **Settings → Commerce** to let customers see their orders:

```twig
{% for order in cms.commerce.orders() %}
	<p>#{{ order.number }}: {{ order.grandTotal|money(order.currency) }}</p>
{% endfor %}
```

## For scripts

Every cart address also answers a script. Send `Accept: application/json` and you get the cart back as JSON.

| Address | What it does |
|---|---|
| `GET /commerce/cart` | The cart |
| `POST /commerce/cart/add` | Add `productId`, with `variantId` and `qty` |
| `POST /commerce/cart/update` | Set a line's `qty` |
| `POST /commerce/cart/remove` | Remove a line |
| `POST /commerce/cart/clear` | Empty the cart |
| `POST /commerce/cart/discount` | Apply a `code` |
| `POST /commerce/checkout` | Start a checkout |

Every `POST` needs the site's CSRF token. A refusal is always the same shape:

```json
{"error": {"code": "out_of_stock", "message": "Only 2 left in stock."}}
```

A script cannot set a price. Any `price`, `amount` or `total` it sends is ignored.
