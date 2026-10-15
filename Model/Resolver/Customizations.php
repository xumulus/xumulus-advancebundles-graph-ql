<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Xumulus\AdvanceBundles\Model\CustomOption\Entry;
use Xumulus\AdvanceBundles\Model\CustomOption\QuoteApplier;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;

/**
 * BundleCartItem.xab_customizations and BundleOrderItem.xab_customizations.
 *
 * Reads the structured entries the main module stores when a bundle is added to the cart: the xab_custom_options
 * quote item option, copied to the order item's product options of the same name. Stored entries are shown even
 * when the extension has since been disabled, since they are part of the line.
 */
class Customizations implements ResolverInterface
{
    /**
     * @var QuoteApplier
     */
    private QuoteApplier $quoteApplier;

    /**
     * @var CustomOptionUid
     */
    private CustomOptionUid $uid;

    /**
     * @param QuoteApplier $quoteApplier
     * @param CustomOptionUid $uid
     */
    public function __construct(QuoteApplier $quoteApplier, CustomOptionUid $uid)
    {
        $this->quoteApplier = $quoteApplier;
        $this->uid = $uid;
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $model = $value['model'] ?? null;
        if ($model instanceof AbstractItem) {
            $entries = $this->quoteApplier->getEntries($model);
        } elseif ($model instanceof OrderItemInterface) {
            $options = $model->getProductOptions();
            $entries = is_array($options) && isset($options[QuoteApplier::OPTION_CODE])
                && is_array($options[QuoteApplier::OPTION_CODE])
                ? array_filter($options[QuoteApplier::OPTION_CODE], 'is_array')
                : [];
        } else {
            $entries = [];
        }

        return array_map([$this, 'format'], array_values($entries));
    }

    /**
     * One stored entry in the schema's shape.
     *
     * @param array<string, mixed> $entry see Entry
     * @return array<string, int|string|null>
     */
    private function format(array $entry): array
    {
        $optionId = (int)($entry[Entry::OPTION_ID] ?? 0);
        $selectionId = (int)($entry[Entry::SELECTION_ID] ?? 0);
        $customOptionId = (int)($entry[Entry::CUSTOM_OPTION_ID] ?? 0);

        return [
            'uid' => $this->uid->encode($optionId, $selectionId, $customOptionId),
            'option_id' => $optionId ?: null,
            'option_label' => isset($entry[Entry::OPTION_TITLE]) ? (string)$entry[Entry::OPTION_TITLE] : null,
            'selection_id' => $selectionId ?: null,
            'selection_label' => isset($entry[Entry::PRODUCT_NAME]) ? (string)$entry[Entry::PRODUCT_NAME] : null,
            'custom_option_id' => $customOptionId ?: null,
            'title' => isset($entry[Entry::CUSTOM_OPTION_TITLE]) ? (string)$entry[Entry::CUSTOM_OPTION_TITLE] : null,
            'type' => isset($entry[Entry::TYPE]) ? (string)$entry[Entry::TYPE] : null,
            'value' => (string)($entry[Entry::VALUE] ?? ''),
        ];
    }
}
