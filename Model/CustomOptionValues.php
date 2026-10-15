<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Reads bundle item custom option values from the option uids of a GraphQL item input.
 *
 * Shared by the buy request providers of addProductsToCart and, in the wishlist bridge, addProductsToWishlist:
 * both get core's "bundle/..." uids for the chosen selections and our "xab-custom/..." uids for the texts.
 */
class CustomOptionValues
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
     * @param CustomOptionUid $uid
     */
    public function __construct(CustomOptionUid $uid)
    {
        $this->uid = $uid;
    }

    /**
     * Selections chosen through core's bundle option uids.
     *
     * Reads "bundle/<option>/<selection>/<qty>" selected options and "bundle/<option>/<selection>[/...]" entered
     * options (a typed quantity); anything else is skipped.
     *
     * @param string[] $uids
     * @param bool $lastPerOption keep only the last selection of each option, as core's wishlist provider does
     * @return array<int, array<int, true>> [option id][selection id]
     */
    public function getChosenSelections(array $uids, bool $lastPerOption = false): array
    {
        $chosen = [];
        foreach ($uids as $uid) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $parts = explode('/', (string)base64_decode((string)$uid, true));
            if (count($parts) < 3 || $parts[0] !== self::BUNDLE_TYPE
                || !ctype_digit($parts[1]) || !ctype_digit($parts[2])
            ) {
                continue;
            }
            if ($lastPerOption) {
                $chosen[(int)$parts[1]] = [];
            }
            $chosen[(int)$parts[1]][(int)$parts[2]] = true;
        }

        return $chosen;
    }

    /**
     * The values of our entered options, keyed the way the main module reads them.
     *
     * Entered options with other uids are skipped. A uid of ours must be well formed, name a chosen selection and
     * appear only once.
     *
     * @param array<array{string,string}> $enteredOptions [uid, value] pairs
     * @param array<int, array<int, true>> $chosen see getChosenSelections()
     * @return array<int, array<int, array<int, string>>> [bundle option id][selection id][custom option id] => text
     * @throws LocalizedException for a malformed uid of ours, a selection that is not chosen, or a repeated uid
     */
    public function collect(array $enteredOptions, array $chosen): array
    {
        $values = [];
        foreach ($enteredOptions as [$uid, $value]) {
            if (!$this->uid->isCustomOptionUid($uid)) {
                continue;
            }
            $ids = $this->uid->decode($uid);
            if (!isset($chosen[$ids['option_id']][$ids['selection_id']])) {
                throw new LocalizedException(
                    __('The entered option "%1" is for a bundle item that is not selected.', $uid)
                );
            }
            if (isset($values[$ids['option_id']][$ids['selection_id']][$ids['custom_option_id']])) {
                throw new LocalizedException(__('The entered option "%1" is sent more than once.', $uid));
            }
            $values[$ids['option_id']][$ids['selection_id']][$ids['custom_option_id']] = $value;
        }

        return $values;
    }
}
