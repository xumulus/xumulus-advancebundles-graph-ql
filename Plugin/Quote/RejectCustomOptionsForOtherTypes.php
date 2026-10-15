<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Plugin\Quote;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;

/**
 * Keeps the bundle custom option keys our providers add out of the cart items of other product types.
 *
 * The providers only see the input, not the product: a client that sends core's "bundle/..." uids for a simple
 * or configurable product gets bundle_custom_option (and, with values, the entered_options mark) in its buy
 * request. Only the bundle type reads and removes them, so for any other type they were stored as part of the
 * item's info_buyRequest and the texts silently disappeared.
 *
 * Values sent as entered_options for a product that is not a bundle are refused; addProductsToCart reports the
 * exception as a user error. The empty key that only enforces required texts is removed. Registered for the
 * graphql area only.
 */
class RejectCustomOptionsForOtherTypes
{
    /**
     * Check the buy request before the product type prepares it.
     *
     * @param Quote $subject
     * @param Product $product
     * @param DataObject|float|int|string|null $request
     * @param string $processMode
     * @return null
     * @throws LocalizedException when bundle item custom options were entered for a product that is not a bundle
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddProduct(
        Quote $subject,
        Product $product,
        $request = null,
        $processMode = AbstractType::PROCESS_MODE_FULL
    ) {
        if (!$request instanceof DataObject || $product->getTypeId() === BundleType::TYPE_CODE) {
            return null;
        }

        if ($request->getData(CustomOptionDataProvider::VALUES_FLAG)) {
            throw new LocalizedException(
                __('Bundle item custom options can only be entered for a bundle product.')
            );
        }
        $request->unsetData(CustomOptionDataProvider::VALUES_FLAG);
        if ($request->getData(BuyRequestData::KEY) === []) {
            $request->unsetData(BuyRequestData::KEY);
        }

        return null;
    }
}
