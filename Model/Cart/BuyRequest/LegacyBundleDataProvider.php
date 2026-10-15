<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest;

use Magento\QuoteGraphQl\Model\Cart\BuyRequest\BuyRequestDataProviderInterface;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;

/**
 * Enforces required bundle item custom options in the legacy addBundleProductsToCart mutation.
 *
 * That mutation builds its buy request with QuoteGraphQl's own builder, not the one addProductsToCart uses, so
 * CustomOptionDataProvider never sees it. Without bundle_custom_option in the buy request the main module treats
 * the add like an admin or REST one and skips required custom options, which let a client add a bundle whose
 * chosen item requires an engraving without one.
 *
 * The mutation has no way to send the texts, so this only sets the key, empty, for an item with bundle options:
 * a chosen item with a required text then fails as it does in addProductsToCart and on the storefront, and other
 * bundles are added as before. It follows CustomOptionDataProvider's enforceRequired setting and adds nothing
 * while the extension is disabled for the store.
 */
class LegacyBundleDataProvider implements BuyRequestDataProviderInterface
{
    /**
     * @var CustomOptionDataProvider
     */
    private CustomOptionDataProvider $cartProvider;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @param CustomOptionDataProvider $cartProvider
     * @param Config $config
     */
    public function __construct(CustomOptionDataProvider $cartProvider, Config $config)
    {
        $this->cartProvider = $cartProvider;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $cartItemData): array
    {
        $bundleOptions = $cartItemData['bundle_options'] ?? null;
        if (!is_array($bundleOptions) || !$bundleOptions
            || !$this->cartProvider->isEnforcingRequired() || !$this->config->isEnabled()
        ) {
            return [];
        }

        return [BuyRequestData::KEY => []];
    }
}
