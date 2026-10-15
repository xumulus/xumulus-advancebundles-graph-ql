<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Model\ResourceModel\Product\Option\CollectionFactory;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Xumulus\AdvanceBundles\Model\ResourceModel\BundleAttributes;

/**
 * The text custom options of bundle items, as they apply to each bundle selection.
 *
 * All selections of a GraphQL request are handled together: one query for the child products' text custom options
 * (titles and prices of the store included) and one for the selections' custom_option_required overrides (the
 * main module's xab_custom_option_required link extension attribute).
 *
 * A custom option is required when the selection's override is 1, or when the override is not set and the custom
 * option itself is required: the rule of Xumulus\AdvanceBundles\Model\CustomOption\Validator.
 */
class SelectionCustomOptions implements ResetAfterRequestInterface
{
    /**
     * Custom option types a bundle item can carry.
     */
    public const TEXT_TYPES = [
        ProductCustomOptionInterface::OPTION_TYPE_FIELD,
        ProductCustomOptionInterface::OPTION_TYPE_AREA,
    ];

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $optionCollectionFactory;

    /**
     * @var BundleAttributes
     */
    private BundleAttributes $bundleAttributes;

    /**
     * @var CustomOptionUid
     */
    private CustomOptionUid $uid;

    /**
     * Text custom options by store id and product id, in sort order.
     *
     * @var array<int, array<int, list<array<string, mixed>>>>
     */
    private array $productOptions = [];

    /**
     * Overrides by selection id: true, false, or null to inherit.
     *
     * @var array<int, bool|null>
     */
    private array $overrides = [];

    /**
     * @param CollectionFactory $optionCollectionFactory
     * @param BundleAttributes $bundleAttributes
     * @param CustomOptionUid $uid
     */
    public function __construct(
        CollectionFactory $optionCollectionFactory,
        BundleAttributes $bundleAttributes,
        CustomOptionUid $uid
    ) {
        $this->optionCollectionFactory = $optionCollectionFactory;
        $this->bundleAttributes = $bundleAttributes;
        $this->uid = $uid;
    }

    /**
     * Custom options and the resolved required flag of bundle selections.
     *
     * @param array<int,array<string,int>> $selections by selection id: option_id and product_id
     * @param int $storeId
     * @return array<int, array{required: bool, options: list<array<string, mixed>>}> by selection id; each option
     *     has uid, option_id, title, type, required, max_characters, price, price_type and sort_order
     */
    public function get(array $selections, int $storeId): array
    {
        $productOptions = $this->loadProductOptions(array_column($selections, 'product_id'), $storeId);
        $overrides = $this->loadOverrides(array_keys($selections));

        $result = [];
        foreach ($selections as $selectionId => $selection) {
            $override = $overrides[$selectionId] ?? null;
            $options = [];
            $anyRequired = false;
            foreach ($productOptions[(int)$selection['product_id']] ?? [] as $option) {
                $option['required'] = $override ?? $option['is_require'];
                $anyRequired = $anyRequired || $option['required'];
                unset($option['is_require']);
                $option['uid'] = $this->uid->encode(
                    (int)$selection['option_id'],
                    (int)$selectionId,
                    (int)$option['option_id']
                );
                $options[] = $option;
            }
            $result[$selectionId] = ['required' => $anyRequired, 'options' => $options];
        }

        return $result;
    }

    /**
     * Text custom options of products, without any selection's setting applied.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, list<array<string, mixed>>> by product id, in sort order; each option has option_id,
     *     title, type, is_require, max_characters, price, price_type and sort_order
     */
    public function getProductOptions(array $productIds, int $storeId): array
    {
        return $this->loadProductOptions($productIds, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->productOptions = [];
        $this->overrides = [];
    }

    /**
     * Text custom options of products, loading only those not loaded yet.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, list<array<string, mixed>>> by product id
     */
    private function loadProductOptions(array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $missing = array_values(array_diff($productIds, array_keys($this->productOptions[$storeId] ?? [])));
        if ($missing) {
            foreach ($missing as $productId) {
                $this->productOptions[$storeId][$productId] = [];
            }

            $collection = $this->optionCollectionFactory->create();
            $collection->addFieldToFilter('main_table.type', ['in' => self::TEXT_TYPES]);
            // Joined by the collection on the product link field; filtering on entity_id works with and without
            // content staging.
            $collection->getSelect()
                ->where('cpe.entity_id IN (?)', $missing)
                ->columns(['xab_product_id' => 'cpe.entity_id']);
            $collection->addTitleToResult($storeId);
            $collection->addPriceToResult($storeId);
            $collection->setOrder('sort_order', 'ASC');
            $collection->setOrder('title', 'ASC');

            /** @var \Magento\Catalog\Model\Product\Option $option */
            foreach ($collection->getItems() as $option) {
                $priceType = strtoupper((string)$option->getPriceType());
                $this->productOptions[$storeId][(int)$option->getData('xab_product_id')][] = [
                    'option_id' => (int)$option->getOptionId(),
                    'title' => (string)$option->getTitle(),
                    'type' => (string)$option->getType(),
                    'is_require' => (bool)$option->getIsRequire(),
                    'max_characters' => max(0, (int)$option->getMaxCharacters()),
                    'price' => (float)$option->getPrice(),
                    'price_type' => $priceType === 'PERCENT' ? 'PERCENT' : 'FIXED',
                    'sort_order' => (int)$option->getSortOrder(),
                ];
            }
        }

        return array_intersect_key($this->productOptions[$storeId] ?? [], array_flip($productIds));
    }

    /**
     * Required overrides of selections, loading only those not loaded yet.
     *
     * @param int[] $selectionIds
     * @return array<int, bool|null> by selection id
     */
    private function loadOverrides(array $selectionIds): array
    {
        $selectionIds = array_values(array_unique(array_filter(array_map('intval', $selectionIds))));
        $missing = array_values(array_diff($selectionIds, array_keys($this->overrides)));
        if ($missing) {
            $stored = $this->bundleAttributes->getCustomOptionRequired($missing);
            foreach ($missing as $selectionId) {
                $this->overrides[$selectionId] = isset($stored[$selectionId]) ? (bool)$stored[$selectionId] : null;
            }
        }

        return array_intersect_key($this->overrides, array_flip($selectionIds));
    }
}
