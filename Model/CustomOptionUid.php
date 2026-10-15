<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Encodes and decodes the uid of a bundle item custom option.
 *
 * The uid follows core's convention for cart item option uids (base64 of a slash separated path whose first part
 * names the kind of option, like "bundle/<option>/<selection>/<qty>" or "custom-option/<id>"):
 *
 *     base64("xab-custom/<bundle option id>/<selection id>/<custom option id>")
 *
 * A client reads it from BundleItemOption.xab_custom_options[].uid and sends it back, with the text the shopper
 * entered, as an entered_options entry of addProductsToCart.
 */
class CustomOptionUid
{
    /**
     * First part of the decoded uid.
     */
    public const TYPE = 'xab-custom';

    /**
     * Uid of a bundle item custom option.
     *
     * @param int $optionId bundle option id
     * @param int $selectionId bundle selection id
     * @param int $customOptionId the child product's custom option id
     * @return string
     */
    public function encode(int $optionId, int $selectionId, int $customOptionId): string
    {
        return base64_encode(implode('/', [self::TYPE, $optionId, $selectionId, $customOptionId]));
    }

    /**
     * Whether a uid is one of ours, whatever its other parts.
     *
     * @param string $uid
     * @return bool
     */
    public function isCustomOptionUid(string $uid): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $decoded = base64_decode($uid, true);

        return is_string($decoded) && explode('/', $decoded, 2)[0] === self::TYPE;
    }

    /**
     * Ids carried by a uid of ours.
     *
     * @param string $uid
     * @return array{option_id: int, selection_id: int, custom_option_id: int}
     * @throws LocalizedException when the uid is ours but malformed
     */
    public function decode(string $uid): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $decoded = base64_decode($uid, true);
        $parts = is_string($decoded) ? explode('/', $decoded) : [];
        if (count($parts) !== 4 || $parts[0] !== self::TYPE) {
            throw new LocalizedException(__('Wrong format of the entered option data'));
        }

        $ids = [];
        foreach (array_slice($parts, 1) as $part) {
            if (!ctype_digit($part) || (int)$part <= 0) {
                throw new LocalizedException(__('Wrong format of the entered option data'));
            }
            $ids[] = (int)$part;
        }

        return ['option_id' => $ids[0], 'selection_id' => $ids[1], 'custom_option_id' => $ids[2]];
    }
}
