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
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;

/**
 * Turns the bundle item custom options of an addProductsToCart item into the buy request the main module reads.
 *
 * Each entered option whose uid is one of ours (see CustomOptionUid) becomes
 * bundle_custom_option[bundle option id][selection id][custom option id] = text. The main module then validates
 * the values when the bundle is prepared for the cart (required, max characters, chosen selection) and stores
 * them on the quote item; a validation message comes back as one of the mutation's user_errors.
 *
 * A value is accepted only for a selection chosen in the same cart item, through core's "bundle/..." selected or
 * entered options; that the custom option belongs to the selection's product is checked once the product is known
 * (Plugin\RejectUnknownCustomOptions).
 *
 * For a bundle item the key is always set, even with no values, so the main module enforces required custom
 * options as the storefront does: the API offers a way to send them. Set the enforceRequired argument to false
 * in di.xml to only enforce them when the client sends at least one value.
 *
 * Nothing is added while the extension is disabled for the store, so our entered options are then ignored.
 */
class CustomOptionDataProvider implements BuyRequestDataProviderInterface
{
    /**
     * First part of core's bundle option uids.
     */
    private const BUNDLE_TYPE = 'bundle';

    /**
     * @var CustomOptionUid
     */
    private CustomOptionUid $uid;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var bool
     */
    private bool $enforceRequired;

    /**
     * @param CustomOptionUid $uid
     * @param Config $config
     * @param bool $enforceRequired
     */
    public function __construct(CustomOptionUid $uid, Config $config, bool $enforceRequired = true)
    {
        $this->uid = $uid;
        $this->config = $config;
        $this->enforceRequired = $enforceRequired;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException for a malformed uid of ours, or a value for a selection that is not chosen
     */
    public function execute(CartItem $cartItem): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        $chosen = $this->getChosenSelections($cartItem);
        $values = [];
        foreach ($cartItem->getEnteredOptions() ?? [] as $enteredOption) {
            $uid = $enteredOption->getUid();
            if (!$this->uid->isCustomOptionUid($uid)) {
                continue;
            }
            $ids = $this->uid->decode($uid);
            if (!isset($chosen[$ids['option_id']][$ids['selection_id']])) {
                throw new LocalizedException(
                    __('The entered option "%1" is for a bundle item that is not selected.', $uid)
                );
            }
            $values[$ids['option_id']][$ids['selection_id']][$ids['custom_option_id']] = $enteredOption->getValue();
        }

        if (!$values && (!$chosen || !$this->enforceRequired)) {
            return [];
        }

        return [BuyRequestData::KEY => $values];
    }

    /**
     * Selections chosen through core's bundle option uids: "bundle/<option>/<selection>/<qty>" selected options and
     * "bundle/<option>/<selection>" entered options (a typed quantity).
     *
     * @param CartItem $cartItem
     * @return array<int, array<int, true>> [option id][selection id]
     */
    private function getChosenSelections(CartItem $cartItem): array
    {
        $uids = [];
        foreach ($cartItem->getSelectedOptions() ?? [] as $selectedOption) {
            $uids[] = $selectedOption->getId();
        }
        foreach ($cartItem->getEnteredOptions() ?? [] as $enteredOption) {
            $uids[] = $enteredOption->getUid();
        }

        $chosen = [];
        foreach ($uids as $uid) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $parts = explode('/', (string)base64_decode($uid, true));
            if (count($parts) < 3 || $parts[0] !== self::BUNDLE_TYPE
                || !ctype_digit($parts[1]) || !ctype_digit($parts[2])
            ) {
                continue;
            }
            $chosen[(int)$parts[1]][(int)$parts[2]] = true;
        }

        return $chosen;
    }
}
