# Xumulus Advanced Bundles GraphQL

GraphQL support for [Xumulus Advanced Bundles](https://www.xumulus.com) (`xumulus/xumulus-advancebundles`), for
headless storefronts such as PWA Studio, Hyvä Checkout or a custom frontend.

- **Bundle options**: whether each option is shown, the CMS block above it (as stored and rendered), and the
  gallery single-choice setting.
- **Bundle item custom options**: the text custom options (such as an engraving) of each bundle item, with the
  bundle selection's "required" setting applied, so the inputs can be rendered.
- **Add to cart**: `addProductsToCart` accepts the text the shopper entered. The main module validates it exactly as
  it does on the storefront: required fields, maximum length, and that the item is chosen.
- **Cart and orders**: bundle cart items and order items list the text entered.
- **Wishlists** (optional, separate package `xumulus/advancebundles-graph-ql-wishlist`): texts are saved with
  `addProductsToWishlist` and carried over by `addWishlistItemsToCart`. See [Wishlists](#wishlists).

It is a separate package so that stores without Magento's GraphQL modules can install the main module on its own.
The wishlist support is a package of its own for the same reason: this one does not depend on Magento_Wishlist or
Magento_WishlistGraphQl, so stores that disable or remove them can still install it.

---

## Requirements

| | |
|---|---|
| Adobe Commerce / Magento Open Source | 2.4.7, 2.4.8, 2.4.9 |
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 |
| Xumulus Advanced Bundles | 2.0.0 or later |
| Magento modules | `Magento_BundleGraphQl`, `Magento_QuoteGraphQl` (part of every standard install) |

## Installation

```bash
composer require xumulus/advancebundles-graph-ql
bin/magento module:enable Xumulus_AdvanceBundlesGraphQl
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

The module has no settings of its own. It follows the main module's **Enable** setting
(*Stores > Configuration > Xumulus > Advanced Bundles > General*) for each store view.

---

## Reading bundle products

```graphql
{
  products(filter: { sku: { eq: "XAB-BUNDLE-DYN" } }) {
    items {
      sku
      ... on BundleProduct {
        items {
          uid
          title
          xab_visible          # false: hidden; send its is_default items yourself (see below)
          xab_cms_block        # CMS block identifier (or numeric id) as stored on the option
          xab_cms_block_html   # that block rendered for the current store
          xab_gallery_single   # gallery options: single choice (true) or several (false)
          options {
            uid
            label
            xab_custom_option_required
            xab_custom_options {
              uid              # send this back with the shopper's text
              option_id
              title
              type             # "field" or "area"
              required
              max_characters   # 0 = no limit
              price
              price_type       # FIXED or PERCENT
              sort_order
            }
          }
        }
      }
    }
  }
}
```

| Field | Type | Value |
|---|---|---|
| `BundleItem.xab_visible` | Boolean | `false` when the option is set to hidden. The storefront then submits the option's default selections itself; a headless client should send the option's `is_default` items in `selected_options`. The server neither adds nor enforces them. |
| `BundleItem.xab_cms_block` | String | The option's CMS block reference as stored, or null. |
| `BundleItem.xab_cms_block_html` | String | The block's content for the current store, with directives and widgets processed. Null when no active block is assigned to the store. |
| `BundleItem.xab_gallery_single` | Boolean | Whether a gallery option allows a single choice. |
| `BundleItemOption.xab_custom_option_required` | Boolean | Whether the item's text must be entered when the item is chosen: the selection's own setting when it has one, otherwise whether any of the item's text custom options is required. `false` when the item has none. |
| `BundleItemOption.xab_custom_options` | [XabBundleItemCustomOption!] | The item's `field` and `area` custom options. The `required` flag of each has the selection's setting applied. |

While the extension is disabled for the store, every one of these fields is `null`.

All bundle items in a response are resolved together. Each of the four data sources takes one query, however many
products, options and selections the response contains: option settings, CMS blocks, the children's custom options
and the selections' required settings.

## Adding to the cart

Choose the bundle items with core's `selected_options` (the `uid` of each `BundleItemOption`). Send the shopper's
text as `entered_options`, using the `uid` from `xab_custom_options`:

```graphql
mutation {
  addProductsToCart(
    cartId: "…"
    cartItems: [{
      sku: "XAB-BUNDLE-DYN"
      quantity: 1
      selected_options: ["YnVuZGxlLzUvOS8x", "YnVuZGxlLzYvMTUvMQ==", "YnVuZGxlLzcvMTkvMQ=="]
      entered_options: [
        { uid: "eGFiLWN1c3RvbS81LzkvMQ==", value: "23" }
        { uid: "eGFiLWN1c3RvbS83LzE5LzE=", value: "Happy Birthday" }
      ]
    }]
  ) {
    user_errors { code message }
    cart {
      items {
        ... on BundleCartItem {
          xab_customizations { option_label selection_label title value }
        }
      }
    }
  }
}
```

The `uid` is `base64("xab-custom/<bundle option id>/<selection id>/<custom option id>")`, built the same way as
core's `bundle/…` and `custom-option/…` uids.

How the values are handled:

- They are checked by the main module, as on the storefront. A missing required text, or a text that is too long,
  is returned in `user_errors` and the bundle is not added. The text is stored as entered, trimmed, so numeric text
  such as `"23"` stays text.
- **Required texts are enforced for every bundle added through `addProductsToCart` or the legacy
  `addBundleProductsToCart`**, even when the request sends no `xab-custom` options. A client that does not know
  about this module therefore cannot add a bundle whose chosen item requires an engraving. That matches the
  storefront. To enforce required texts only when a client sends at least one value (for both mutations), set the
  argument in a `di.xml` of your own:

  ```xml
  <type name="Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider">
      <arguments>
          <argument name="enforceRequired" xsi:type="boolean">false</argument>
      </arguments>
  </type>
  ```

- A value that does not belong to the bundle is refused with a user error. It is not silently dropped. This
  covers a uid for an item not chosen in the same cart item, a uid from another product, a custom option the item
  does not have, a malformed uid, the same uid sent twice in one cart item, and `xab-custom` values for a product
  that is not a bundle. This strict check applies only to values sent as `entered_options`.
  Buy requests that reach the cart another way, such as `reorderItems` re-adding an order placed with 1.3.x (whose
  storefront form posted an input for every item, chosen or not), get the main module's usual handling: values
  for items that are not chosen are ignored.
- While the extension is disabled, `xab-custom` entered options are ignored.
- The same bundle with the same texts adds to the existing cart line's quantity. With different texts it becomes
  a separate line.

The legacy `addBundleProductsToCart` mutation has no input for the texts; use `addProductsToCart` to send them.
It does enforce required texts as described above, so a bundle whose chosen item requires one cannot be added with
it. As with any problem in that mutation, the message comes back in the response's `errors`, not as a user error.

### Wishlists

Install the wishlist bridge as well when your storefront uses Magento's wishlist mutations:

```bash
composer require xumulus/advancebundles-graph-ql-wishlist
bin/magento module:enable Xumulus_AdvanceBundlesGraphQlWishlist
```

It saves the `xab-custom` entered options of `addProductsToWishlist` and `updateProductsInWishlist` with the
wishlist item and carries them over in `addWishlistItemsToCart` (see [its README](https://github.com/xumulus/xumulus-advancebundles-graph-ql-wishlist)). Without it,
Magento drops those entered options when it saves a wishlist item, and `addWishlistItemsToCart` cannot move a
bundle whose chosen item requires a text, because required texts are enforced as described above.

## Cart and order output

`BundleCartItem.xab_customizations` and `BundleOrderItem.xab_customizations` list what was entered:

| Field | Value |
|---|---|
| `uid` | Same as `XabBundleItemCustomOption.uid` |
| `bundle_option_id`, `option_label` | The bundle option (`BundleItem.option_id`) |
| `bundle_selection_id`, `selection_label` | The chosen item (`BundleItemOption.id`) |
| `custom_option_id`, `title`, `type` | The custom option (`XabBundleItemCustomOption.option_id`) |
| `value` | The text entered |

The list is never null; it is empty for a bundle without texts. Labels are stored when the item is added, so they
show what the shopper saw. Stored values stay listed even if
the extension is later disabled.

Cart items and orders created with 1.3.x have no stored entries; their texts are read back from the stored request
(`info_buyRequest`) instead, for items that show customization lines (`additional_options`). Only non-empty texts
for the bundle's chosen items are listed. The option label and item name are the ones stored with the line; the
custom option `title` and `type` are the current ones, or null if the custom option has since been deleted. These
items are resolved together: one query for the bundle selections and one per store for the custom options.

---

## Notes

- **GraphQL caching.** Magento caches `products` queries sent with GET under the product's cache tags. Saving the
  bundle product refreshes the new fields. Editing only a CMS block referenced by an option does not refresh
  `xab_cms_block_html` in cached responses until the cache entry expires or is cleaned.
- **Content staging (Adobe Commerce).** Custom options are matched to products by `entity_id`. This has been tested
  on Magento Open Source only.

## Development

CI (`.github/workflows/ci.yml`) runs phpcs and PHPUnit on PHP 8.1 (Magento 2.4.7) and 8.4 (Magento 2.4.9) on
every push. It checks out the private Xumulus packages it builds on into `dev/ci/deps/` with read-only deploy keys,
then runs `dev/ci/run-phpcs.sh` and `dev/ci/run-unit.sh`. With those checkouts in place the scripts run the same
way locally, for example in the CI image:

```bash
docker run --rm -u 0 -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PWD":/app -w /app --entrypoint dev/ci/run-unit.sh \
    markoshust/magento-php:8.4-fpm-2
```

```bash
# Unit tests (PHPUnit 10.5 to 12), from a Magento install's vendor/:
XAB_AUTOLOAD=/var/www/html/vendor/autoload.php /var/www/html/vendor/bin/phpunit -c phpunit.xml.dist

# GraphQL API tests against a running store with the QA fixtures (bash, curl, jq):
Test/Api/run-graphql-tests.sh
XAB_DB='docker exec -i 249-db-1 mariadb -umagento -pmagento magento -N -B' Test/Api/run-graphql-tests.sh
XAB_EXPECT_DISABLED=1 Test/Api/run-graphql-tests.sh   # with the extension disabled
# Wishlist bridge too, when it is installed (a customer whose wishlist the tests may fill):
XAB_CUSTOMER='someone@example.com Password123!' Test/Api/run-graphql-tests.sh
# Also check a guest order and a guest cart created with 1.3.x (no stored entries):
XAB_LEGACY_ORDER='000000003 qa-order@example.test Tester' XAB_LEGACY_CART='<masked cart id>' \
    XAB_DB='docker exec -i 249-db-1 mariadb -umagento -pmagento magento -N -B' Test/Api/run-graphql-tests.sh

# Count the SQL issued for the xab fields (should stay at 1 per source), from the Magento root:
php <path>/dev/count-graphql-queries.php 'XAB-BUNDLE-DYN,XAB-BUNDLE-FIX,24-WG080'

# Coding standard (0 errors at severity 10):
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml,graphqls --error-severity=10 \
    --ignore-annotations --ignore='*/dev/*,*/.phpunit.cache/*' <path>
```

## License

See [LICENSE.txt](LICENSE.txt).
