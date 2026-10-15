<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Model\CartItems;

use Magento\Wishlist\Model\Item;
use Magento\WishlistGraphQl\Model\CartItems\CartItemsRequestDataProviderInterface;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;

/**
 * Carries the bundle item custom options saved on a wishlist item over to addWishlistItemsToCart.
 *
 * WishlistGraphQl turns a wishlist item's buy request into an addProductsToCart cart item. Core maps bundle_option
 * to "bundle/..." selected options but knows nothing about bundle_custom_option, so without this the saved texts
 * were dropped, and a bundle whose chosen item requires a text could not be moved to the cart at all.
 *
 * Each saved text becomes an xab-custom entered option, which the GraphQL package's cart provider turns
 * back into bundle_custom_option. Only values for selections core passes on are included, so they never fail the
 * "item not selected" check: core encodes an option only when the buy request holds a single selection id for it
 * (a select or radio option). Empty values are left out, as the storefront form posts an input for every item.
 * Both the current location and the one 1.3.x used (bundle_option[custom_option]) are read.
 */
class CustomOptionDataProvider implements CartItemsRequestDataProviderInterface
{
    /**
     * @var CustomOptionUid
     */
    private CustomOptionUid $uid;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param CustomOptionUid $uid
     * @param Config $config
     */
    public function __construct(CustomOptionUid $uid, Config $config)
    {
        $this->uid = $uid;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function execute(Item $wishlistItem, ?string $sku): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $buyRequest = $wishlistItem->getBuyRequest();
        $values = BuyRequestData::extract($buyRequest);
        $bundleOption = $buyRequest->getData('bundle_option');
        if (!$values || !is_array($bundleOption)) {
            return [];
        }

        $enteredOptions = [];
        foreach ($values as $optionId => $bySelection) {
            $selectionId = $bundleOption[$optionId] ?? null;
            if (!$this->isId($optionId) || !is_scalar($selectionId) || !$this->isId($selectionId)
                || !is_array($bySelection) || !isset($bySelection[$selectionId])
                || !is_array($bySelection[$selectionId])
            ) {
                continue;
            }
            foreach ($bySelection[$selectionId] as $customOptionId => $value) {
                if (!$this->isId($customOptionId) || !is_scalar($value) || trim((string)$value) === '') {
                    continue;
                }
                $enteredOptions[] = [
                    'uid' => $this->uid->encode((int)$optionId, (int)$selectionId, (int)$customOptionId),
                    'value' => (string)$value,
                ];
            }
        }

        return $enteredOptions ? ['entered_options' => $enteredOptions] : [];
    }

    /**
     * Whether a key or value is a positive integer id.
     *
     * @param mixed $value
     * @return bool
     */
    private function isId($value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int)$value > 0);
    }
}
