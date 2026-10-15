<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Model\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Wishlist\Model\Wishlist\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;

/**
 * Saves the bundle item custom options of addProductsToWishlist and updateProductsInWishlist items.
 *
 * Magento_Wishlist builds the wishlist buy request with its own providers, which skip entered options they do not
 * know, so a text sent with an xab-custom uid was silently dropped and the item could later not be moved to the
 * cart when the text was required. Each value becomes bundle_custom_option[option][selection][custom option] in
 * the stored buy request, where the storefront keeps it too and Model\CartItems\CustomOptionDataProvider reads it
 * back for addWishlistItemsToCart.
 *
 * The same input checks as addProductsToCart apply: a malformed or repeated uid, or one for a selection that is
 * not chosen in the same item, is refused; Magento_Wishlist returns the message as a user error. Core's wishlist
 * provider keeps one selection per bundle option (the last one sent), so only that one counts as chosen. Like the
 * storefront, a wishlist item is saved without checking required texts or lengths: the main module validates them
 * when the item is moved to the cart.
 *
 * Nothing is added while the extension is disabled for the store, so our entered options are then ignored.
 */
class CustomOptionDataProvider implements BuyRequestDataProviderInterface
{
    /**
     * @var CustomOptionValues
     */
    private CustomOptionValues $values;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param CustomOptionValues $values
     * @param Config $config
     */
    public function __construct(CustomOptionValues $values, Config $config)
    {
        $this->values = $values;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException for a malformed or repeated uid of ours, or a value for a selection that is not
     *     chosen
     */
    public function execute(WishlistItem $wishlistItemData, ?int $productId): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $uids = [];
        $entered = [];
        foreach ($wishlistItemData->getSelectedOptions() ?? [] as $selectedOption) {
            $uids[] = $selectedOption->getId();
        }
        foreach ($wishlistItemData->getEnteredOptions() ?? [] as $enteredOption) {
            $uids[] = $enteredOption->getUid();
            $entered[] = [$enteredOption->getUid(), $enteredOption->getValue()];
        }

        $values = $this->values->collect($entered, $this->values->getChosenSelections($uids, true));

        return $values ? [BuyRequestData::KEY => $values] : [];
    }
}
