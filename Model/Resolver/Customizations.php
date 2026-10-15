<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Xumulus\AdvanceBundles\Model\CustomOption\Entry;
use Xumulus\AdvanceBundles\Model\CustomOption\QuoteApplier;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\LegacyCustomizations;

/**
 * BundleCartItem.xab_customizations and BundleOrderItem.xab_customizations.
 *
 * Reads the structured entries the main module stores when a bundle is added to the cart: the xab_custom_options
 * quote item option, copied to the order item's product options of the same name. Stored entries are shown even
 * when the extension has since been disabled, since they are part of the line.
 *
 * Items added before that option existed (1.3.x carts and orders) are read back from their buy request instead,
 * see LegacyCustomizations; those are loaded together for the whole response through a deferred value.
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
     * @var LegacyCustomizations
     */
    private LegacyCustomizations $legacy;

    /**
     * @var ValueFactory
     */
    private ValueFactory $valueFactory;

    /**
     * @param QuoteApplier $quoteApplier
     * @param CustomOptionUid $uid
     * @param LegacyCustomizations $legacy
     * @param ValueFactory $valueFactory
     */
    public function __construct(
        QuoteApplier $quoteApplier,
        CustomOptionUid $uid,
        LegacyCustomizations $legacy,
        ValueFactory $valueFactory
    ) {
        $this->quoteApplier = $quoteApplier;
        $this->uid = $uid;
        $this->legacy = $legacy;
        $this->valueFactory = $valueFactory;
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
            return [];
        }
        if ($entries) {
            return array_map([$this, 'format'], array_values($entries));
        }

        $request = $this->legacy->read($model);
        if ($request === null) {
            return [];
        }
        $this->legacy->register($request);

        return $this->valueFactory->create(
            fn () => array_map([$this, 'format'], $this->legacy->getEntries($request))
        );
    }

    /**
     * One stored entry in the schema's shape.
     *
     * @param array<string,mixed> $entry see Entry
     * @return array<string, int|string|null>
     */
    private function format(array $entry): array
    {
        $optionId = (int)($entry[Entry::OPTION_ID] ?? 0);
        $selectionId = (int)($entry[Entry::SELECTION_ID] ?? 0);
        $customOptionId = (int)($entry[Entry::CUSTOM_OPTION_ID] ?? 0);

        return [
            'uid' => $this->uid->encode($optionId, $selectionId, $customOptionId),
            'bundle_option_id' => $optionId ?: null,
            'option_label' => isset($entry[Entry::OPTION_TITLE]) ? (string)$entry[Entry::OPTION_TITLE] : null,
            'bundle_selection_id' => $selectionId ?: null,
            'selection_label' => isset($entry[Entry::PRODUCT_NAME]) ? (string)$entry[Entry::PRODUCT_NAME] : null,
            'custom_option_id' => $customOptionId ?: null,
            'title' => isset($entry[Entry::CUSTOM_OPTION_TITLE]) ? (string)$entry[Entry::CUSTOM_OPTION_TITLE] : null,
            'type' => isset($entry[Entry::TYPE]) ? (string)$entry[Entry::TYPE] : null,
            'value' => (string)($entry[Entry::VALUE] ?? ''),
        ];
    }
}
