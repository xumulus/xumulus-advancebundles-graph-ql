<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Cart\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Quote\Model\Cart\Data\CartItem;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;

/**
 * Turns the bundle item custom options of an addProductsToCart item into the buy request the main module reads.
 *
 * Each entered option whose uid is one of ours (see CustomOptionUid) becomes
 * bundle_custom_option[bundle option id][selection id][custom option id] = text. The main module then validates
 * the values when the bundle is prepared for the cart (required, max characters, chosen selection) and stores
 * them on the quote item; a validation message comes back as one of the mutation's user_errors.
 *
 * A value is accepted only for a selection chosen in the same cart item, through core's "bundle/..." selected or
 * entered options, and only once per uid; that the custom option belongs to the selection's product is checked
 * once the product is known (Plugin\RejectUnknownCustomOptions). The buy request is marked (VALUES_FLAG) so that
 * check applies to these values only: buy requests that reach the cart some other way in the graphql area, such as
 * reorderItems re-adding an order saved by 1.3.x, keep the main module's lenient handling.
 *
 * For a bundle item the key is always set, even with no values, so the main module enforces required custom
 * options as the storefront does: the API offers a way to send them. Set the enforceRequired argument to false
 * in di.xml to only enforce them when the client sends at least one value; LegacyBundleDataProvider (the
 * addBundleProductsToCart mutation) follows the same setting.
 *
 * Nothing is added while the extension is disabled for the store, so our entered options are then ignored.
 */
class CustomOptionDataProvider implements BuyRequestDataProviderInterface
{
    /**
     * Buy request key marking values that came from entered_options. Removed before the buy request is stored.
     */
    public const VALUES_FLAG = '_xab_graphql_custom_option';

    /**
     * @var CustomOptionValues
     */
    private CustomOptionValues $values;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var bool
     */
    private bool $enforceRequired;

    /**
     * @param CustomOptionValues $values
     * @param Config $config
     * @param bool $enforceRequired
     */
    public function __construct(CustomOptionValues $values, Config $config, bool $enforceRequired = true)
    {
        $this->values = $values;
        $this->config = $config;
        $this->enforceRequired = $enforceRequired;
    }

    /**
     * Whether required custom options are enforced for bundles added without any values.
     *
     * @return bool
     */
    public function isEnforcingRequired(): bool
    {
        return $this->enforceRequired;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException for a malformed or repeated uid of ours, or a value for a selection that is not
     *     chosen
     */
    public function execute(CartItem $cartItem): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $uids = [];
        $entered = [];
        foreach ($cartItem->getSelectedOptions() ?? [] as $selectedOption) {
            $uids[] = $selectedOption->getId();
        }
        foreach ($cartItem->getEnteredOptions() ?? [] as $enteredOption) {
            $uids[] = $enteredOption->getUid();
            $entered[] = [$enteredOption->getUid(), $enteredOption->getValue()];
        }

        $chosen = $this->values->getChosenSelections($uids);
        $values = $this->values->collect($entered, $chosen);
        if ($values) {
            return [BuyRequestData::KEY => $values, self::VALUES_FLAG => true];
        }

        return $chosen && $this->enforceRequired ? [BuyRequestData::KEY => []] : [];
    }
}
