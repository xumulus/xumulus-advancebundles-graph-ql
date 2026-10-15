<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Plugin;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Wishlist\Model\Item;
use Magento\WishlistGraphQl\Model\CartItems\CartItemsRequestBuilder;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;

/**
 * Moves bundle item custom options out of bundle_option before WishlistGraphQl reads a wishlist item.
 *
 * Wishlist items saved with 1.3.x keep the texts in bundle_option[custom_option]. Core's bundle provider for
 * addWishlistItemsToCart treats every bundle_option key as a bundle option id, so such an item failed with an
 * "Undefined array key" error. The main module rewrites buy requests the same way when they reach the bundle type
 * (Plugin\Bundle\NormalizeBuyRequest), but WishlistGraphQl reads the stored one directly.
 *
 * Only the item's in-memory info_buyRequest option changes; a value already in bundle_custom_option wins.
 */
class NormalizeLegacyBuyRequest
{
    /**
     * @var Json
     */
    private Json $serializer;

    /**
     * @param Json $serializer
     */
    public function __construct(Json $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Rewrite the wishlist item's buy request before core's providers read it.
     *
     * @param CartItemsRequestBuilder $subject
     * @param Item $wishlistItem
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeBuild(CartItemsRequestBuilder $subject, Item $wishlistItem)
    {
        $option = $wishlistItem->getOptionByCode('info_buyRequest');
        $value = $option ? $option->getValue() : null;
        if (!is_string($value) || strpos($value, BuyRequestData::LEGACY_KEY) === false) {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        if (!is_array($data) || !isset($data['bundle_option']) || !is_array($data['bundle_option'])
            || !array_key_exists(BuyRequestData::LEGACY_KEY, $data['bundle_option'])
        ) {
            return null;
        }

        $customOptions = $data['bundle_option'][BuyRequestData::LEGACY_KEY];
        unset($data['bundle_option'][BuyRequestData::LEGACY_KEY]);
        if (is_array($customOptions) && !is_array($data[BuyRequestData::KEY] ?? null)) {
            $data[BuyRequestData::KEY] = $customOptions;
        }
        $option->setValue($this->serializer->serialize($data));

        return null;
    }
}
