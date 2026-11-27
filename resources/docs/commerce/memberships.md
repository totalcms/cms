---
title: "Memberships"
description: "Sell access: a product puts its buyer in an access group, and they stay in it while their subscription runs or their purchase stands."
since: "3.7.0"
related:
  - commerce/subscriptions
  - commerce/products
  - auth/access-groups
  - forms/builder
  - site-builder/overview
---

# Memberships

A product can put its buyer in an [access group](docs/auth/access-groups). Pages, collections and files that group can see are then theirs. That is a membership.

- Sold as a **subscription**, the buyer is in the group while the subscription runs.
- Sold **once**, the buyer is in the group unless the order is fully refunded.

The [shop starter](docs/site-builder/starters#shop) sets all of this up for you, with a sample membership. To add a membership to a site you already have, follow the steps below.

## Set it up

### 1. Give customers a place to sign in

A membership belongs to an account, so the buyer must have one.

1. Make a collection for your customers with the `auth` schema, such as `customers`. Do not use the admin's own `auth` collection.
2. Choose it under **Settings → Commerce → Customer Accounts Collection**.
3. Add a registration form and a sign-in form to your site. See [Form Builder](docs/forms/builder).

Someone signed in to that collection is a customer. Anyone else, an admin included, is a guest to the store.

### 2. Make the access group

Under **Access Groups**, make a group such as `members`. Give it what members may see.

### 3. Say which groups a product may give

Under **Settings → Commerce → Groups a Product May Grant**, choose the group.

This list is a safety limit. A product can only ever give a group that is on it. The `admin` group can never be given, even if someone adds it to the list.

### 4. Point the product at the group

Open the product, go to **Subscription and Membership**, and choose the group under **Grants Access Group**. Only the groups from step 3 are offered.

For a membership people pay for every month, also set **Billing** to **Subscription**. See [Subscriptions](docs/commerce/subscriptions).

### 5. Protect the pages

On a Site Builder page, add the `auth` middleware, set **Access Collection** to your customers collection, and choose the group under **Access Groups**. A visitor who is not signed in is sent to sign in, and one who is not in the group is refused. See [Restricting `auth` to Access Groups](docs/site-builder/overview#restricting-auth-to-access-groups).

To show or hide part of a page:

```twig
{% if cms.auth.userHasAccess('members', 'customers') %}
    {# What only members see #}
{% else %}
    <a href="/join">Become a member</a>
{% endif %}
```

### A one-time variant of a membership product

The group is set on the product, not on a variant. So a one-time variant of a product that grants a group grants that group too, and for good: a one-time purchase keeps the buyer in the group unless the order is fully refunded.

To give access to subscribers only, put the one-time option on a product that grants no group.

## What the buyer does

1. They register or sign in.
2. They add the membership to the cart and check out.
3. When the payment is confirmed, they are in the group. This takes a few seconds.

A guest with a membership in the cart is shown a note in place of the checkout form: sign in first. Nothing is charged. To add a link to your own sign-up page beside the sign-in link, name it on the form:

```twig
{{ cms.cart.checkoutForm({registerUrl: '/register'}) }}
```

## When access is given and taken away

Total CMS works out a customer's groups from everything they have bought, each time something changes: a payment, a refund, a subscription that ends.

| What happens | The group |
|---|---|
| A one-time membership is paid | Given |
| That order is partly refunded | Kept |
| That order is fully refunded | Taken away |
| The payment is disputed | Kept while the dispute is open, taken away if you lose it |
| A subscription's first payment is confirmed | Given |
| A renewal charge fails and Stripe is retrying | Kept |
| A subscription is paused | Taken away, and given back when it resumes |
| The subscriber cancels | Kept until the period they paid for ends |
| A subscription ends | Taken away |

If two purchases give the same group, the customer keeps it until both are gone.

### Groups on the list belong to your sales

A group on **Groups a Product May Grant** is held only while a purchase backs it. If you add a customer to such a group by hand and they have bought nothing, they are taken out of it the next time their purchases change.

To give someone a free membership, use one of these:

- A 100% discount code that lasts **Every payment**.
- A second group that is **not** on the list, with the same access, that you add people to by hand.

Every other group a customer is in is never touched.

## If access could not be given

If the buyer's account was deleted before the payment was confirmed, the order is still paid and is flagged **Access not granted**. Once the account exists again, a super admin opens the order and chooses **Run fulfillment again**. For a subscription, open it and choose **Check with Stripe**.

## For developers

A line that gives a group carries `accessGroup` in the `order.paid` payload and in the four [subscription events](docs/commerce/events-and-extensions#subscription-events). A listener can use it to do more than add a group, such as creating a seat in another system.
