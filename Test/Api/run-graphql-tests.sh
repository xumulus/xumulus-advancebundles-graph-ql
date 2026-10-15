#!/usr/bin/env bash
# GraphQL API tests for Xumulus_AdvanceBundlesGraphQl against a running store with the QA fixtures.
#
# Usage:
#   Test/Api/run-graphql-tests.sh                       # extension enabled (the default)
#   XAB_EXPECT_DISABLED=1 Test/Api/run-graphql-tests.sh # after: bin/magento config:set advancebundle/general/advancebundle_enable 0
#
# Environment:
#   XAB_GRAPHQL_URL  GraphQL endpoint (default https://localhost/graphql; the certificate is not checked)
#   XAB_STORE_CODE   Store header (default "default")
#   XAB_DB           Command that runs SQL read from stdin and prints tab-separated rows without headers, to also
#                    compare the product fields with the database, e.g.
#                    XAB_DB='docker exec -i 249-db-1 mariadb -umagento -pmagento magento -N -B'
#   XAB_LEGACY_ORDER "number email lastname" of a guest order placed with 1.3.x (no xab_custom_options), e.g.
#                    '000000003 qa-order@example.test Tester'; its xab_customizations must list the texts.
#   XAB_LEGACY_CART  Masked id of a guest cart filled with 1.3.x. With XAB_DB, both are compared with the
#                    additional_options lines stored on the items.
#   XAB_CUSTOMER     "email password" of a customer, to also test the wishlist bridge
#                    (Xumulus_AdvanceBundlesGraphQlWishlist). Its default wishlist is emptied first, and moved items
#                    land in its cart.
#
# Fixtures (see the main module's Test/e2e): XAB-BUNDLE-DYN (2046) with options 5 select, 6 radio (CMS block
# xab-option-note), 7 checkbox, 8 multi (hidden); child XAB-A (2041) has the text custom option 1 "Engraving
# text" (field, max 30, not required); XAB-BUNDLE-FIX (2047) forces that engraving to be required on its
# selections of XAB-A (29, 34, 39, 44).
#
# Needs bash, curl, jq and base64. Creates guest carts; places no orders.
set -uo pipefail

URL="${XAB_GRAPHQL_URL:-https://localhost/graphql}"
STORE="${XAB_STORE_CODE:-default}"
EXPECT_DISABLED="${XAB_EXPECT_DISABLED:-0}"

PASS=0
FAIL=0

b64() { printf '%s' "$1" | base64; }

# gql QUERY [VARIABLES_JSON] [BEARER_TOKEN] -> response JSON on stdout
gql() {
    local vars="${2:-}" auth=()
    [ -z "$vars" ] && vars='{}'
    [ -n "${3:-}" ] && auth=(-H "Authorization: Bearer $3")
    jq -n --arg q "$1" --argjson v "$vars" '{query: $q, variables: $v}' \
        | curl -sk "$URL" -H 'Content-Type: application/json' -H "Store: $STORE" ${auth[@]+"${auth[@]}"} --data @-
}

# check NAME JSON JQ_FILTER: passes when the filter yields true
check() {
    local name="$1" json="$2" filter="$3"
    if [ "$(printf '%s' "$json" | jq -r "$filter" 2>/dev/null)" = "true" ]; then
        PASS=$((PASS + 1))
        echo "ok   - $name"
    else
        FAIL=$((FAIL + 1))
        echo "FAIL - $name"
        echo "       filter: $filter"
        echo "       response: $(printf '%s' "$json" | head -c 1500)"
    fi
}

new_cart() { gql 'mutation { createEmptyCart }' | jq -r .data.createEmptyCart; }

ADD='mutation($cart: String!, $items: [CartItemInput!]!) {
  addProductsToCart(cartId: $cart, cartItems: $items) {
    user_errors { code message }
    cart {
      total_quantity
      items {
        uid quantity product { sku }
        ... on BundleCartItem {
          bundle_options { id values { id quantity } }
          xab_customizations {
            uid bundle_option_id option_label bundle_selection_id selection_label custom_option_id title type value
          }
        }
      }
    }
  }
}'

# add SKU SELECTED_UIDS_JSON ENTERED_JSON [CART] -> response
add() {
    local cart="${4:-$(new_cart)}"
    gql "$ADD" "$(jq -n --arg c "$cart" --arg sku "$1" --argjson sel "$2" --argjson ent "$3" \
        '{cart: $c, items: [{sku: $sku, quantity: 1, selected_options: $sel, entered_options: $ent}]}')"
}

entered() { jq -n --arg u "$(b64 "$1")" --arg v "$2" '{uid: $u, value: $v}'; }

S5_9=$(b64 bundle/5/9/1)
S6_15=$(b64 bundle/6/15/1)
S7_19=$(b64 bundle/7/19/1)
DYN_SEL=$(jq -n --arg a "$S5_9" --arg b "$S6_15" --arg c "$S7_19" '[$a, $b, $c]')

echo "# GraphQL endpoint $URL, store $STORE, extension expected $([ "$EXPECT_DISABLED" = 1 ] && echo disabled || echo enabled)"

# --- Schema --------------------------------------------------------------------------------------------------------
SCHEMA=$(gql '{
  bundleItem: __type(name: "BundleItem") { fields { name type { name kind } } }
  bundleItemOption: __type(name: "BundleItemOption") { fields { name } }
  customOption: __type(name: "XabBundleItemCustomOption") { fields { name } }
  cartItem: __type(name: "BundleCartItem") { fields { name } }
  orderItem: __type(name: "BundleOrderItem") { fields { name type { kind } } }
  customization: __type(name: "XabBundleItemCustomization") { fields { name } }
}')
check "introspection: BundleItem has the xab fields" "$SCHEMA" \
    '[.data.bundleItem.fields[].name] | contains(["xab_visible","xab_cms_block","xab_cms_block_html","xab_gallery_single"])'
check "introspection: BundleItemOption has the xab fields" "$SCHEMA" \
    '[.data.bundleItemOption.fields[].name] | contains(["xab_custom_option_required","xab_custom_options"])'
check "introspection: XabBundleItemCustomOption" "$SCHEMA" \
    '[.data.customOption.fields[].name] | sort == ["max_characters","option_id","price","price_type","required","sort_order","title","type","uid"]'
check "introspection: BundleCartItem.xab_customizations" "$SCHEMA" '[.data.cartItem.fields[].name] | index("xab_customizations") != null'
check "introspection: BundleOrderItem.xab_customizations is a non-null list, like BundleCartItem's" "$SCHEMA" \
    '.data.orderItem.fields[] | select(.name == "xab_customizations") | .type.kind == "NON_NULL"'
check "introspection: XabBundleItemCustomization names the bundle ids bundle_option_id / bundle_selection_id" "$SCHEMA" \
    '[.data.customization.fields[].name] | sort == ["bundle_option_id","bundle_selection_id","custom_option_id","option_label","selection_label","title","type","uid","value"]'

# --- Product fields ------------------------------------------------------------------------------------------------
PRODUCT=$(gql '{
  products(filter: {sku: {in: ["XAB-BUNDLE-DYN", "XAB-BUNDLE-FIX"]}}) {
    items {
      sku
      ... on BundleProduct {
        items {
          option_id xab_visible xab_cms_block xab_cms_block_html xab_gallery_single
          options {
            id xab_custom_option_required
            xab_custom_options { uid option_id title type required max_characters price price_type sort_order }
          }
        }
      }
    }
  }
}')
DYN='(.data.products.items[] | select(.sku == "XAB-BUNDLE-DYN"))'
FIX='(.data.products.items[] | select(.sku == "XAB-BUNDLE-FIX"))'
check "products: no errors" "$PRODUCT" '.errors == null'

if [ "$EXPECT_DISABLED" = 1 ]; then
    check "disabled: every BundleItem xab field is null" "$PRODUCT" \
        "[$DYN.items[] | .xab_visible, .xab_cms_block, .xab_cms_block_html, .xab_gallery_single] | all(. == null)"
    check "disabled: every BundleItemOption xab field is null" "$PRODUCT" \
        "[$DYN.items[].options[] | .xab_custom_option_required, .xab_custom_options] | all(. == null)"
else
    check "options 5-8: xab_visible" "$PRODUCT" "[$DYN.items[] | [.option_id, .xab_visible]] == [[5,true],[6,true],[7,true],[8,false]]"
    check "options 5-8: xab_cms_block" "$PRODUCT" "[$DYN.items[].xab_cms_block] == [null,\"xab-option-note\",null,null]"
    check "option 6: xab_cms_block_html is the rendered block" "$PRODUCT" \
        "($DYN.items[] | select(.option_id == 6) | .xab_cms_block_html | test(\"<strong>Fixture CMS block</strong>\"))"
    check "options 5-8: xab_gallery_single" "$PRODUCT" "[$DYN.items[].xab_gallery_single] == [false,false,false,false]"
    check "selection 9 (XAB-A): the engraving custom option" "$PRODUCT" \
        "($DYN.items[].options[] | select(.id == 9) | .xab_custom_options) == [{\"uid\":\"$(b64 xab-custom/5/9/1)\",\"option_id\":1,\"title\":\"Engraving text\",\"type\":\"field\",\"required\":false,\"max_characters\":30,\"price\":0,\"price_type\":\"FIXED\",\"sort_order\":1}]"
    check "XAB-A selections of every option carry the engraving with their own uid" "$PRODUCT" \
        "[$DYN.items[] | .option_id as \$o | .options[] | select(.id == (9,14,19,24)) | .xab_custom_options[0].uid] == [\"$(b64 xab-custom/5/9/1)\",\"$(b64 xab-custom/6/14/1)\",\"$(b64 xab-custom/7/19/1)\",\"$(b64 xab-custom/8/24/1)\"]"
    check "selections of other children have no custom options" "$PRODUCT" \
        "[$DYN.items[].options[] | select(.id != 9 and .id != 14 and .id != 19 and .id != 24) | .xab_custom_options] | all(. == [])"
    check "XAB-BUNDLE-DYN: nothing required (no override, option not required)" "$PRODUCT" \
        "[$DYN.items[].options[].xab_custom_option_required] | all(. == false)"
    check "XAB-BUNDLE-FIX: the selections' override makes the engraving required" "$PRODUCT" \
        "[$FIX.items[].options[] | select(.id == (29,34,39,44)) | .xab_custom_option_required, .xab_custom_options[0].required] | all(. == true)"

    if [ -n "${XAB_DB:-}" ]; then
        DB_OPTIONS=$(printf '%s\n' "SELECT CONCAT('[', GROUP_CONCAT(CONCAT('[', o.option_id, ',', IF(COALESCE(o.hide,'') = '0', 'false', 'true'), ',', IF(TRIM(COALESCE(o.custom_bundle_block,'')) = '', 'null', CONCAT('\"', TRIM(o.custom_bundle_block), '\"')), ',', IF(COALESCE(o.gallery_selection,0) = 1, 'true', 'false'), ']') ORDER BY o.position, o.option_id), ']') FROM catalog_product_bundle_option o JOIN catalog_product_entity e ON e.entity_id = o.parent_id WHERE e.sku = 'XAB-BUNDLE-DYN';" | $XAB_DB)
        check "database: option settings match catalog_product_bundle_option" "$PRODUCT" \
            "[$DYN.items[] | [.option_id, .xab_visible, .xab_cms_block, .xab_gallery_single]] == $DB_OPTIONS"
        DB_REQUIRED=$(printf '%s\n' "SELECT CONCAT('[', GROUP_CONCAT(CONCAT('[', s.selection_id, ',', IF(COALESCE(s.custom_option_required, (SELECT MAX(co.is_require) FROM catalog_product_option co WHERE co.product_id = s.product_id AND co.type IN ('field','area'))) = 1, 'true', 'false'), ']') ORDER BY s.selection_id), ']') FROM catalog_product_bundle_selection s JOIN catalog_product_entity e ON e.entity_id = s.parent_product_id WHERE e.sku IN ('XAB-BUNDLE-DYN','XAB-BUNDLE-FIX') AND s.product_id <> 2043;" | $XAB_DB)
        check "database: required flags match the selection override / custom option" "$PRODUCT" \
            "[.data.products.items[].items[].options[] | [.id, .xab_custom_option_required]] | sort == $DB_REQUIRED"
    fi
fi

# --- Add to cart ---------------------------------------------------------------------------------------------------
if [ "$EXPECT_DISABLED" = 1 ]; then
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 23), $(entered xab-custom/9/29/1 forged)]")
    check "disabled: xab entered options are ignored, no error" "$R" '.data.addProductsToCart.user_errors == []'
    check "disabled: only the chosen selections are in the cart" "$R" \
        '[.data.addProductsToCart.cart.items[0].bundle_options[].values[].id] == [9,15,19]'
    check "disabled: no customizations stored" "$R" '.data.addProductsToCart.cart.items[0].xab_customizations == []'
else
    CART=$(new_cart)
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 23), $(entered xab-custom/7/19/1 'Happy Birthday')]" "$CART")
    check "engravings \"23\" and \"Happy Birthday\": no user errors" "$R" '.data.addProductsToCart.user_errors == []'
    check "one cart line, qty 1" "$R" '.data.addProductsToCart.cart.total_quantity == 1 and (.data.addProductsToCart.cart.items | length) == 1'
    check "no extra items: exactly the three chosen selections" "$R" \
        '[.data.addProductsToCart.cart.items[0].bundle_options[] | [.id, [.values[].id]]] == [[5,[9]],[6,[15]],[7,[19]]]'
    check "cart line shows both customizations, numeric text kept as text" "$R" \
        "[.data.addProductsToCart.cart.items[0].xab_customizations[] | [.uid, .bundle_option_id, .option_label, .bundle_selection_id, .selection_label, .custom_option_id, .title, .type, .value]] == [[\"$(b64 xab-custom/5/9/1)\",5,\"Select Option\",9,\"AB Child Alpha\",1,\"Engraving text\",\"field\",\"23\"],[\"$(b64 xab-custom/7/19/1)\",7,\"Checkbox Option\",19,\"AB Child Alpha\",1,\"Engraving text\",\"field\",\"Happy Birthday\"]]"

    CARTQ=$(gql 'query($c: String!) { cart(cart_id: $c) { items { ... on BundleCartItem { xab_customizations { value } } } } }' \
        "$(jq -n --arg c "$CART" '{c: $c}')")
    check "customizations are read back from the saved cart" "$CARTQ" \
        '[.data.cart.items[0].xab_customizations[].value] == ["23","Happy Birthday"]'

    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 23), $(entered xab-custom/7/19/1 'Happy Birthday')]" "$CART")
    check "same bundle with the same text again: quantity merges into the line" "$R" \
        '(.data.addProductsToCart.cart.items | length) == 1 and .data.addProductsToCart.cart.total_quantity == 2'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 24)]" "$CART")
    check "same bundle with other text: a separate line" "$R" '(.data.addProductsToCart.cart.items | length) == 2'

    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" '[]')
    check "no engraving where none is required: added" "$R" \
        '.data.addProductsToCart.user_errors == [] and .data.addProductsToCart.cart.items[0].xab_customizations == []'

    FIX_SEL=$(jq -n --arg a "$(b64 bundle/9/29/1)" --arg b "$(b64 bundle/10/35/1)" '[$a, $b]')
    R=$(add XAB-BUNDLE-FIX "$FIX_SEL" '[]')
    check "required engraving missing: user error, nothing added" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("Please enter \"Engraving text\" for \"AB Child Alpha\"")) and .data.addProductsToCart.cart.total_quantity == 0'
    R=$(add XAB-BUNDLE-FIX "$FIX_SEL" "[$(entered xab-custom/9/29/1 '   ')]")
    check "required engraving blank: user error" "$R" '.data.addProductsToCart.user_errors | length == 1'
    R=$(add XAB-BUNDLE-FIX "$FIX_SEL" "[$(entered xab-custom/9/29/1 'For Sam')]")
    check "required engraving given: added" "$R" \
        '.data.addProductsToCart.user_errors == [] and .data.addProductsToCart.cart.items[0].xab_customizations[0].value == "For Sam"'

    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 1234567890123456789012345678901)]")
    check "31 characters where 30 are allowed: user error" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("at most 30 characters")) and .data.addProductsToCart.cart.total_quantity == 0'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 123456789012345678901234567890)]")
    check "exactly 30 characters: added" "$R" '.data.addProductsToCart.user_errors == []'

    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/9/29/1 forged)]")
    check "uid of another product's option: rejected" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("not selected")) and .data.addProductsToCart.cart.total_quantity == 0'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/10/1 forged)]")
    check "uid of a selection that is not chosen: rejected" "$R" '.data.addProductsToCart.user_errors | length == 1'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/999 forged)]")
    check "uid with a custom option the item does not have: rejected" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("not available")) and .data.addProductsToCart.cart.total_quantity == 0'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/6/15/1 forged)]")
    check "uid for a chosen item without text options: rejected" "$R" '.data.addProductsToCart.user_errors | length == 1'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/x 1)]")
    check "malformed uid: rejected" "$R" \
        '.data.addProductsToCart.user_errors[0].message == "Wrong format of the entered option data"'
    R=$(add XAB-A '[]' "[$(entered xab-custom/5/9/1 x)]")
    check "xab uid on a simple product: rejected" "$R" '.data.addProductsToCart.user_errors | length == 1'
    R=$(add XAB-A "$(jq -n --arg a "$S5_9" '[$a]')" "[$(entered xab-custom/5/9/1 x)]")
    check "regression: xab uid on a simple product sent with core bundle uids: rejected, nothing added" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("only be entered for a bundle product")) and .data.addProductsToCart.cart.total_quantity == 0'
    R=$(add XAB-A "$(jq -n --arg a "$S5_9" '[$a]')" '[]')
    check "a simple product sent with core bundle uids but no texts: added as before" "$R" \
        '.data.addProductsToCart.user_errors == [] and .data.addProductsToCart.cart.total_quantity == 1'
    R=$(add XAB-BUNDLE-DYN "$DYN_SEL" "[$(entered xab-custom/5/9/1 First), $(entered xab-custom/5/9/1 Second)]")
    check "regression: the same uid twice in one cart item: rejected, nothing added" "$R" \
        '(.data.addProductsToCart.user_errors[0].message | test("sent more than once")) and .data.addProductsToCart.cart.total_quantity == 0'

    # Legacy addBundleProductsToCart: no input for texts, but required ones are enforced the same way
    LEGACY_ADD='mutation($cart: String!, $sku: String!, $opts: [BundleOptionInput!]!) {
      addBundleProductsToCart(input: {cart_id: $cart, cart_items: [{data: {sku: $sku, quantity: 1}, bundle_options: $opts}]}) {
        cart { total_quantity items { ... on BundleCartItem { xab_customizations { value } } } }
      }
    }'
    legacy_add() {
        gql "$LEGACY_ADD" "$(jq -n --arg c "$(new_cart)" --arg sku "$1" --argjson o "$2" '{cart: $c, sku: $sku, opts: $o}')"
    }
    R=$(legacy_add XAB-BUNDLE-FIX '[{"id":9,"quantity":1,"value":["29"]},{"id":10,"quantity":1,"value":["35"]},{"id":11,"quantity":1,"value":["40"]},{"id":12,"quantity":1,"value":["45"]}]')
    check "regression: addBundleProductsToCart with a required engraving it cannot send: refused" "$R" \
        '(.errors[0].message | test("Please enter \"Engraving text\" for \"AB Child Alpha\"")) and .data.addBundleProductsToCart == null'
    R=$(legacy_add XAB-BUNDLE-DYN '[{"id":5,"quantity":1,"value":["9"]},{"id":6,"quantity":1,"value":["15"]},{"id":7,"quantity":1,"value":["19"]}]')
    check "addBundleProductsToCart without required texts: added as before" "$R" \
        '.errors == null and .data.addBundleProductsToCart.cart.total_quantity == 1 and .data.addBundleProductsToCart.cart.items[0].xab_customizations == []'
fi

# --- Wishlist bridge (Xumulus_AdvanceBundlesGraphQlWishlist) -------------------------------------------------------
if [ -n "${XAB_CUSTOMER:-}" ] && [ "$EXPECT_DISABLED" != 1 ]; then
    read -r C_EMAIL C_PASSWORD <<<"$XAB_CUSTOMER"
    TOKEN=$(gql 'mutation($e: String!, $p: String!) { generateCustomerToken(email: $e, password: $p) { token } }' \
        "$(jq -n --arg e "$C_EMAIL" --arg p "$C_PASSWORD" '{e: $e, p: $p}')" | jq -r '.data.generateCustomerToken.token // empty')
    check "wishlist: customer token" "$(jq -n --arg t "$TOKEN" '{t: $t}')" '.t != ""'
    WL=$(gql '{ customer { wishlists { id items_v2 { items { id } } } } }' '' "$TOKEN")
    WL_ID=$(printf '%s' "$WL" | jq -r '.data.customer.wishlists[0].id')
    OLD_ITEMS=$(printf '%s' "$WL" | jq -c '[.data.customer.wishlists[0].items_v2.items[].id]')
    if [ "$OLD_ITEMS" != "[]" ]; then
        gql 'mutation($w: ID!, $i: [ID!]!) { removeProductsFromWishlist(wishlistId: $w, wishlistItemsIds: $i) { user_errors { message } } }' \
            "$(jq -n --arg w "$WL_ID" --argjson i "$OLD_ITEMS" '{w: $w, i: $i}')" "$TOKEN" >/dev/null
    fi
    WL_ADD='mutation($w: ID!, $items: [WishlistItemInput!]!) {
      addProductsToWishlist(wishlistId: $w, wishlistItems: $items) {
        user_errors { code message }
        wishlist { items_v2 { items { id product { sku } } } }
      }
    }'
    wl_add() {
        gql "$WL_ADD" "$(jq -n --arg w "$WL_ID" --argjson sel "$1" --argjson ent "$2" \
            '{w: $w, items: [{sku: "XAB-BUNDLE-FIX", quantity: 1, selected_options: $sel, entered_options: $ent}]}')" "$TOKEN"
    }
    FIX_ALL=$(jq -n --arg a "$(b64 bundle/9/29/1)" --arg b "$(b64 bundle/10/35/1)" --arg c "$(b64 bundle/11/40/1)" --arg d "$(b64 bundle/12/45/1)" '[$a, $b, $c, $d]')

    R=$(wl_add "$FIX_ALL" "[$(entered xab-custom/9/30/1 x)]")
    check "regression: wishlist uid for an item that is not chosen: user error" "$R" \
        '.data.addProductsToWishlist.user_errors[0].message | test("not selected")'
    R=$(wl_add "$FIX_ALL" "[$(entered xab-custom/9/29/1 A), $(entered xab-custom/9/29/1 B)]")
    check "wishlist: the same uid twice: user error" "$R" '.data.addProductsToWishlist.user_errors[0].message | test("sent more than once")'
    R=$(wl_add "$FIX_ALL" "[$(entered xab-custom/9/29/1 WishText)]")
    check "regression: wishlist bundle with engravings: saved without user errors" "$R" \
        '.data.addProductsToWishlist.user_errors == [] and ([.data.addProductsToWishlist.wishlist.items_v2.items[].product.sku] == ["XAB-BUNDLE-FIX"])'
    WL_ITEM=$(printf '%s' "$R" | jq -r '.data.addProductsToWishlist.wishlist.items_v2.items[0].id')
    if [ -n "${XAB_DB:-}" ]; then
        DB_REQ=$(printf '%s\n' "SELECT value FROM wishlist_item_option WHERE wishlist_item_id = $WL_ITEM AND code = 'info_buyRequest';" | $XAB_DB)
        check "database: the wishlist item stores the texts in bundle_custom_option" "$DB_REQ" \
            '.bundle_custom_option == {"9":{"29":{"1":"WishText"}}}'
    fi
    R=$(gql 'mutation($w: ID!, $i: [ID!]!) {
      addWishlistItemsToCart(wishlistId: $w, wishlistItemIds: $i) { status add_wishlist_items_to_cart_user_errors { message } }
    }' "$(jq -n --arg w "$WL_ID" --arg i "$WL_ITEM" '{w: $w, i: [$i]}')" "$TOKEN")
    check "regression: addWishlistItemsToCart moves the bundle whose engraving is required" "$R" \
        '.data.addWishlistItemsToCart.status == true and .data.addWishlistItemsToCart.add_wishlist_items_to_cart_user_errors == []'
    R=$(gql '{ customerCart { items { product { sku } ... on BundleCartItem { xab_customizations { bundle_option_id bundle_selection_id value } } } } }' '' "$TOKEN")
    check "wishlist: the cart line carries the engraving of the select option" "$R" \
        '[.data.customerCart.items[] | select(.product.sku == "XAB-BUNDLE-FIX") | .xab_customizations[] | select(.value == "WishText") | [.bundle_option_id, .bundle_selection_id]] | any(. == [9,29])'
fi

# --- Carts and orders created with 1.3.x -------------------------------------------------------------------------
# Regression: they have no xab_custom_options, so the texts are read back from the stored buy request.
CUSTOMIZATION_VALUES='[.xab_customizations[].value]'
ADDITIONAL_VALUES='[(.additional_options // [])[] | select(.value != "Customizations") | .value]'

if [ -n "${XAB_LEGACY_ORDER:-}" ] && [ "$EXPECT_DISABLED" != 1 ]; then
    read -r L_NUMBER L_EMAIL L_LASTNAME <<<"$XAB_LEGACY_ORDER"
    R=$(gql 'query($n: String!, $e: String!, $l: String!) {
      guestOrder(input: {number: $n, email: $e, lastname: $l}) {
        items { ... on BundleOrderItem { xab_customizations { option_label selection_label title value } } }
      }
    }' "$(jq -n --arg n "$L_NUMBER" --arg e "$L_EMAIL" --arg l "$L_LASTNAME" '{n: $n, e: $e, l: $l}')")
    check "1.3.x order $L_NUMBER: xab_customizations lists the texts with labels" "$R" \
        '[.data.guestOrder.items[] | select(.xab_customizations != null) | .xab_customizations[]] as $c
         | ($c | length) > 0 and ($c | all(.value != "" and .option_label != null and .selection_label != null))'
    if [ -n "${XAB_DB:-}" ]; then
        DB_OPTIONS=$(printf '%s\n' "SELECT i.product_options FROM sales_order_item i JOIN sales_order o ON o.entity_id = i.order_id WHERE o.increment_id = '$L_NUMBER' AND i.parent_item_id IS NULL AND i.product_type = 'bundle' ORDER BY i.item_id;" | $XAB_DB | jq -sc "[.[] | $ADDITIONAL_VALUES]")
        check "1.3.x order $L_NUMBER: the texts match additional_options" "$R" \
            "[.data.guestOrder.items[] | select(.xab_customizations != null) | $CUSTOMIZATION_VALUES] == $DB_OPTIONS"
    fi
fi

if [ -n "${XAB_LEGACY_CART:-}" ] && [ "$EXPECT_DISABLED" != 1 ]; then
    R=$(gql 'query($c: String!) {
      cart(cart_id: $c) { items { uid ... on BundleCartItem { xab_customizations { option_label selection_label value } } } }
    }' "$(jq -n --arg c "$XAB_LEGACY_CART" '{c: $c}')")
    check "1.3.x cart: some bundle line lists texts" "$R" \
        '[.data.cart.items[] | select(.xab_customizations != null) | .xab_customizations[]] | length > 0'
    if [ -n "${XAB_DB:-}" ]; then
        DB_OPTIONS=$(printf '%s\n' "SELECT CONCAT('{\"additional_options\":', COALESCE((SELECT o.value FROM quote_item_option o WHERE o.item_id = i.item_id AND o.code = 'additional_options'), '[]'), '}') FROM quote_item i JOIN quote_id_mask m ON m.quote_id = i.quote_id WHERE m.masked_id = '$XAB_LEGACY_CART' AND i.parent_item_id IS NULL AND i.product_type = 'bundle' ORDER BY i.item_id;" | $XAB_DB | jq -sc "[.[] | $ADDITIONAL_VALUES]")
        check "1.3.x cart: the texts match additional_options, line by line" "$R" \
            "[.data.cart.items[] | select(.xab_customizations != null) | $CUSTOMIZATION_VALUES] == $DB_OPTIONS"
    fi
fi

echo
echo "# $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
