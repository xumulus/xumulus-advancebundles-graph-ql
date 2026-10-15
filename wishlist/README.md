# Xumulus Advanced Bundles GraphQL: Wishlist

Wishlist support for [Xumulus Advanced Bundles GraphQL](../README.md) (`xumulus/advancebundles-graph-ql`): the
text a shopper enters for a bundle item, such as an engraving, is kept on a wishlist bundle and moved with it to the
cart.

It is a package of its own so that `xumulus/advancebundles-graph-ql` does not depend on Magento_Wishlist. Install
this one only where Magento_Wishlist and Magento_WishlistGraphQl are enabled.

---

## Requirements

| | |
|---|---|
| Adobe Commerce / Magento Open Source | 2.4.7, 2.4.8, 2.4.9 |
| PHP | 8.1, 8.2, 8.3, 8.4, 8.5 |
| Xumulus Advanced Bundles and its GraphQL package | 2.0.0 or later |
| Magento modules | `Magento_Wishlist`, `Magento_WishlistGraphQl` |

## Installation

```bash
composer require xumulus/advancebundles-graph-ql-wishlist
bin/magento module:enable Xumulus_AdvanceBundlesGraphQlWishlist
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

There are no settings. It follows the main module's **Enable** setting for each store view: while the extension is
disabled, `xab-custom` entered options are ignored, as in `addProductsToCart`.

## Saving texts on a wishlist item

`addProductsToWishlist` and `updateProductsInWishlist` take the same input as `addProductsToCart`: the bundle items
in `selected_options`, and the shopper's text as `entered_options` with the `uid` from
`BundleItemOption.xab_custom_options`.

```graphql
mutation {
  addProductsToWishlist(
    wishlistId: "4"
    wishlistItems: [{
      sku: "XAB-BUNDLE-FIX"
      quantity: 1
      selected_options: ["YnVuZGxlLzkvMjkvMQ==", "YnVuZGxlLzEwLzM1LzE="]
      entered_options: [{ uid: "eGFiLWN1c3RvbS85LzI5LzE=", value: "For Sam" }]
    }]
  ) {
    user_errors { code message }
  }
}
```

The texts are stored in the wishlist item's request (`bundle_custom_option`), where the storefront keeps them too.

- A malformed uid, the same uid sent twice, or a uid for an item not chosen in the same wishlist item is refused
  with a user error. Core keeps one chosen item per bundle option on a wishlist item (the last one sent), so only
  that item can carry a text.
- Like the storefront, a wishlist item is saved without checking whether required texts are filled in or too long.
  Those checks run when the item is moved to the cart.

## Moving wishlist items to the cart

`addWishlistItemsToCart` carries the texts saved on a wishlist bundle over to the cart, as `xab-custom` entered
options, so they are validated and stored like any other. Texts saved by 1.3.x (in `bundle_option[custom_option]`)
are read too. Empty texts, and texts for items that are not chosen, are left out.

Core's `addWishlistItemsToCart` passes on only options with a single chosen item (select and radio options); it
does not handle checkbox or multi-select options of a wishlist bundle, so their texts cannot be carried either. A
required text that was never saved on the wishlist item fails the move with a user error, as on the storefront.

## Development

The unit tests run with the GraphQL package's suite (`phpunit.xml.dist` in the repository root). The repository's
`Test/Api/run-graphql-tests.sh` covers these mutations when `XAB_CUSTOMER` is set.

This directory is its own package. Build its release zip from it (`git archive --format=zip HEAD:wishlist`), or
split it into a repository of its own with `git subtree split --prefix=wishlist`.

## License

See [LICENSE.txt](LICENSE.txt).
