<p align="center">
  <img src="./procyon-bag-logotype.png" alt="Procyon Like Bag" width="360" />
</p>

<p align="center">
  <em>"Badges? We ain't got no badges. We don't need no badges. I don't have to show you any stinking badges."</em><br />
  - <em>The Treasure of the Sierra Madre</em>
</p>

# Procyon Like Bag

API-only WooCommerce "favorites bag" plugin.

It stores favorite products:
- for guests using a temporary `token` (cookie/header/query param),
- for logged-in users using a stable DB record per account.

## REST API

Namespace: `procyon-like-bag/v1`

### Session
- `GET /wp-json/procyon-like-bag/v1/session`
- Creates guest token when needed and returns bag snapshot.
- Params:
  - `include_products` (`bool`, default `false`)
  - `auto_merge` (`bool`, default `true`) for logged-in users with guest token.

### List items
- `GET /wp-json/procyon-like-bag/v1/items`
- Params:
  - `include_products` (`bool`, default `false`)

### Add item
- `POST /wp-json/procyon-like-bag/v1/items`
- Body:
  - `product_id` (`int`, required)

### Remove item
- `DELETE /wp-json/procyon-like-bag/v1/items/{product_id}`

### Toggle item
- `POST /wp-json/procyon-like-bag/v1/toggle`
- Body:
  - `product_id` (`int`, required)

### Clear bag
- `DELETE /wp-json/procyon-like-bag/v1/items`

### Count
- `GET /wp-json/procyon-like-bag/v1/count`

### Merge guest -> user
- `POST /wp-json/procyon-like-bag/v1/merge`
- Requires logged-in user.
- Optional body param: `token`.

## Token handling for guests

Guest token can be passed in:
- header: `X-Procyon-Like-Bag-Token`
- request param: `token`
- cookie: `procyon_like_bag_token`

When a guest token exists, API response includes:
- JSON field: `token`
- response header: `X-Procyon-Like-Bag-Token`
