# Guest B2C browsing

FOODEX B2C discovery is public by design. Authentication is not required to browse active retail stores, categories, products, product details, active offers, or a guest cart.

## Public API surface

- `GET /api/v1/stores`
- `GET /api/v1/stores/{store}/categories`
- `GET /api/v1/stores/{store}/products`
- `GET /api/v1/products/{product}`
- `GET /api/v1/stores/{store}/offers`
- `GET /api/v1/cart`
- `POST /api/v1/cart/items`
- `PATCH /api/v1/cart/items/{item}`
- `DELETE /api/v1/cart/items/{item}`

Only active B2C stores and active store-product assignments are exposed. B2B stores are never returned through these guest catalog routes.

## Guest cart identity

Guest carts use an opaque `X-Guest-Token`.

- Calling `GET /cart?store=<id>` without a token initializes an empty guest cart and returns the token in the response header.
- Subsequent cart calls reuse the token.
- A token is bound to one B2C store. Cross-store reuse is rejected.
- Cart item mutations are always constrained to the cart identified by the token, preventing item-ID enumeration across guest carts.

## Protected boundaries

Guest browsing does not weaken authenticated boundaries. Profile and checkout stay behind Sanctum plus the active-user middleware. B2B self-registration remains unavailable.

The Customer Flutter router keeps catalog/cart routes public and redirects protected checkout/account routes to authentication. Backend access rules remain authoritative.
