<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundles\Model\CustomOption\Entry;
use Xumulus\AdvanceBundles\Model\CustomOption\QuoteApplier;
use Xumulus\AdvanceBundlesGraphQl\Model\ResourceModel\SelectionProducts;

/**
 * Rebuilds the custom option entries of cart and order items that have no xab_custom_options option.
 *
 * 1.3.x, and the first 2.0 builds, stored the texts only in the buy request (bundle_option[custom_option], or
 * bundle_custom_option) and as display lines in additional_options. This reads them back from the buy request:
 * - only items that carry additional_options are read, since those lines show the texts were applied;
 * - only non-empty texts for the item's chosen selections (its child items) are kept, as the storefront form
 *   posted an input for every selection;
 * - the option label and the item name come from the child item, as they were when the item was added; the custom
 *   option's title and type are today's, for the item's store, and null when the custom option no longer exists.
 *
 * Usage: read() an item, register() the result, and call getEntries() from a deferred GraphQL value. Everything
 * registered before the first getEntries() is loaded together: one query for the selections and one per store for
 * the custom options.
 *
 * @phpstan-type Child array{option_id: int, selection_id: int|null, product_id: int, name: string,
 *     option_label: string|null}
 * @phpstan-type Request array{store_id: int, values: list<array{int, int, int, string}>,
 *     chosen: array<int, array<int, true>>, children: list<Child>}
 */
class LegacyCustomizations implements ResetAfterRequestInterface
{
    /**
     * @var Json
     */
    private Json $serializer;

    /**
     * @var SelectionProducts
     */
    private SelectionProducts $selectionProducts;

    /**
     * @var SelectionCustomOptions
     */
    private SelectionCustomOptions $customOptions;

    /**
     * Requests registered and not loaded yet.
     *
     * @var list<Request>
     */
    private array $pending = [];

    /**
     * Selections looked up so far: option and product, or null when the selection no longer exists.
     *
     * @var array<int, array{option_id: int, product_id: int}|null>
     */
    private array $selections = [];

    /**
     * @param Json $serializer
     * @param SelectionProducts $selectionProducts
     * @param SelectionCustomOptions $customOptions
     */
    public function __construct(
        Json $serializer,
        SelectionProducts $selectionProducts,
        SelectionCustomOptions $customOptions
    ) {
        $this->serializer = $serializer;
        $this->selectionProducts = $selectionProducts;
        $this->customOptions = $customOptions;
    }

    /**
     * What a cart or order item stores, without touching the database; null when there is nothing to show.
     *
     * @param mixed $item a quote item or an order item
     * @return Request|null
     */
    public function read($item): ?array
    {
        if ($item instanceof AbstractItem) {
            $optionValue = static function (AbstractItem $quoteItem, string $code) {
                $option = $quoteItem->getOptionByCode($code);

                return $option ? $option->getValue() : null;
            };
            $additional = $this->decode($optionValue($item, QuoteApplier::ADDITIONAL_OPTIONS_CODE));
            $buyRequest = $this->decode($optionValue($item, 'info_buyRequest'));
            $children = [];
            foreach ($item->getChildren() as $child) {
                $attributes = $this->decode($optionValue($child, 'bundle_selection_attributes'));
                $selectionId = $optionValue($child, 'selection_id');
                $children[] = $this->child(
                    $attributes,
                    is_numeric($selectionId) ? (int)$selectionId : null,
                    (int)$child->getProductId(),
                    (string)$child->getName()
                );
            }
        } elseif ($item instanceof OrderItem) {
            $options = $item->getProductOptions();
            $options = is_array($options) ? $options : [];
            $additional = $this->decode($options[QuoteApplier::ADDITIONAL_OPTIONS_CODE] ?? null);
            $buyRequest = $this->decode($options['info_buyRequest'] ?? null);
            $children = [];
            foreach ($item->getChildrenItems() as $child) {
                $childOptions = $child->getProductOptions();
                $attributes = $this->decode($childOptions['bundle_selection_attributes'] ?? null);
                $children[] = $this->child($attributes, null, (int)$child->getProductId(), (string)$child->getName());
            }
            $bundleOptions = $this->decode($options['bundle_options'] ?? null);
            foreach ($children as &$child) {
                $label = $bundleOptions[$child['option_id']]['label'] ?? null;
                $child['option_label'] = $child['option_label'] ?? (is_scalar($label) ? (string)$label : null);
            }
            unset($child);
        } else {
            return null;
        }

        if (!$additional) {
            return null;
        }
        $values = $this->readValues(BuyRequestData::extract($buyRequest));
        if (!$values) {
            return null;
        }

        return [
            'store_id' => (int)$item->getStoreId(),
            'values' => $values,
            'chosen' => $this->readChosen($buyRequest),
            'children' => $children,
        ];
    }

    /**
     * Queue a request read by read(), so it is loaded with the others.
     *
     * @param Request $request
     * @return void
     */
    public function register(array $request): void
    {
        $this->pending[] = $request;
    }

    /**
     * The entries of a request, in the order of the item's children.
     *
     * @param Request $request
     * @return list<array<string, int|string|null>> see Entry; unknown labels are null
     */
    public function getEntries(array $request): array
    {
        $this->loadPending();
        $this->loadSelections($this->getSelectionIds($request));

        $productOptions = $this->customOptions->getProductOptions(
            $this->getProductIds($request),
            $request['store_id']
        );
        $customOptions = [];
        foreach ($productOptions as $productId => $options) {
            foreach ($options as $option) {
                $customOptions[$productId][(int)$option['option_id']] = $option;
            }
        }

        $positioned = [];
        foreach ($request['values'] as $index => [$optionId, $selectionId, $customOptionId, $value]) {
            $selection = $this->selections[$selectionId] ?? null;
            $position = $this->findChild($request, $optionId, $selectionId, $selection);
            if ($position === null) {
                if ($request['children'] || !isset($request['chosen'][$optionId][$selectionId])) {
                    continue;
                }
                $child = null;
                $position = PHP_INT_MAX;
            } else {
                $child = $request['children'][$position];
            }

            $productId = $child['product_id'] ?? ($selection['product_id'] ?? null);
            $customOption = $productId ? ($customOptions[$productId][$customOptionId] ?? null) : null;
            $positioned[] = [$position, $index, [
                Entry::OPTION_ID => $optionId,
                Entry::OPTION_TITLE => $child['option_label'] ?? null,
                Entry::SELECTION_ID => $selectionId,
                Entry::PRODUCT_ID => $productId,
                Entry::PRODUCT_NAME => $child['name'] ?? null,
                Entry::CUSTOM_OPTION_ID => $customOptionId,
                Entry::CUSTOM_OPTION_TITLE => $customOption['title'] ?? null,
                Entry::TYPE => $customOption['type'] ?? null,
                Entry::VALUE => $value,
            ]];
        }

        usort($positioned, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_column($positioned, 2);
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->pending = [];
        $this->selections = [];
    }

    /**
     * Load the selections and custom options of every registered request at once.
     *
     * @return void
     */
    private function loadPending(): void
    {
        if (!$this->pending) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];

        $selectionIds = [];
        foreach ($pending as $request) {
            $selectionIds[] = $this->getSelectionIds($request);
        }
        $this->loadSelections(array_merge([], ...$selectionIds));

        $productIds = [];
        foreach ($pending as $request) {
            foreach ($this->getProductIds($request) as $productId) {
                $productIds[$request['store_id']][$productId] = $productId;
            }
        }
        foreach ($productIds as $storeId => $ids) {
            $this->customOptions->getProductOptions(array_values($ids), (int)$storeId);
        }
    }

    /**
     * Look up the selections not looked up yet.
     *
     * @param int[] $selectionIds
     * @return void
     */
    private function loadSelections(array $selectionIds): void
    {
        $missing = array_values(array_diff(array_unique($selectionIds), array_keys($this->selections)));
        if (!$missing) {
            return;
        }
        $rows = $this->selectionProducts->get($missing);
        foreach ($missing as $selectionId) {
            $this->selections[$selectionId] = $rows[$selectionId] ?? null;
        }
    }

    /**
     * Selection ids of a request's values.
     *
     * @param Request $request
     * @return int[]
     */
    private function getSelectionIds(array $request): array
    {
        return array_column($request['values'], 1);
    }

    /**
     * Child product ids of a request, from its children and its selections looked up so far.
     *
     * @param Request $request
     * @return int[]
     */
    private function getProductIds(array $request): array
    {
        $productIds = array_column($request['children'], 'product_id');
        foreach ($this->getSelectionIds($request) as $selectionId) {
            if (isset($this->selections[$selectionId])) {
                $productIds[] = $this->selections[$selectionId]['product_id'];
            }
        }

        return array_values(array_unique(array_filter($productIds)));
    }

    /**
     * Position of the child item for a selection, or null when the item does not hold it.
     *
     * A quote child names its selection. An order child only names its option and product, so the selection is
     * matched through its product; when the selection no longer exists, the option's only child is taken.
     *
     * @param Request $request
     * @param int $optionId
     * @param int $selectionId
     * @param array<string,int>|null $selection option_id and product_id
     * @return int|null
     */
    private function findChild(array $request, int $optionId, int $selectionId, ?array $selection): ?int
    {
        $ofOption = [];
        foreach ($request['children'] as $position => $child) {
            if ($child['selection_id'] !== null) {
                if ($child['selection_id'] === $selectionId) {
                    return $position;
                }
                continue;
            }
            if ($child['option_id'] !== $optionId) {
                continue;
            }
            if ($selection !== null && $selection['option_id'] === $optionId
                && $selection['product_id'] === $child['product_id']
            ) {
                return $position;
            }
            $ofOption[] = $position;
        }

        return $selection === null && count($ofOption) === 1 && isset($request['chosen'][$optionId][$selectionId])
            ? $ofOption[0]
            : null;
    }

    /**
     * One child item in the shape of the request.
     *
     * @param array<mixed> $attributes the child's bundle_selection_attributes
     * @param int|null $selectionId
     * @param int $productId
     * @param string $name
     * @return Child
     */
    private function child(array $attributes, ?int $selectionId, int $productId, string $name): array
    {
        $label = $attributes['option_label'] ?? null;

        return [
            'option_id' => (int)($attributes['option_id'] ?? 0),
            'selection_id' => $selectionId,
            'product_id' => $productId,
            'name' => $name,
            'option_label' => is_scalar($label) ? (string)$label : null,
        ];
    }

    /**
     * Non-empty texts, as [option id, selection id, custom option id, text], trimmed like the main module does.
     *
     * @param array<mixed> $values [option id][selection id][custom option id] => text
     * @return list<array{int, int, int, string}>
     */
    private function readValues(array $values): array
    {
        $result = [];
        foreach ($values as $optionId => $bySelection) {
            if (!$this->isId($optionId) || !is_array($bySelection)) {
                continue;
            }
            foreach ($bySelection as $selectionId => $byCustomOption) {
                if (!$this->isId($selectionId) || !is_array($byCustomOption)) {
                    continue;
                }
                foreach ($byCustomOption as $customOptionId => $value) {
                    $value = is_scalar($value) ? trim(str_replace(["\r\n", "\r"], "\n", (string)$value)) : '';
                    if ($this->isId($customOptionId) && $value !== '') {
                        $result[] = [(int)$optionId, (int)$selectionId, (int)$customOptionId, $value];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Selections the buy request chose, from bundle_option and core's bundle_options_data.
     *
     * @param array<mixed> $buyRequest
     * @return array<int, array<int, true>> [option id][selection id]
     */
    private function readChosen(array $buyRequest): array
    {
        $chosen = [];
        $bundleOption = is_array($buyRequest['bundle_option'] ?? null) ? $buyRequest['bundle_option'] : [];
        foreach ($bundleOption as $optionId => $selectionIds) {
            if (!$this->isId($optionId)) {
                continue;
            }
            foreach (is_array($selectionIds) ? $selectionIds : [$selectionIds] as $selectionId) {
                if ($this->isId($selectionId)) {
                    $chosen[(int)$optionId][(int)$selectionId] = true;
                }
            }
        }
        $optionsData = is_array($buyRequest['bundle_options_data'] ?? null) ? $buyRequest['bundle_options_data'] : [];
        foreach ($optionsData as $optionId => $bySelection) {
            if (!$this->isId($optionId) || !is_array($bySelection)) {
                continue;
            }
            foreach (array_keys($bySelection) as $selectionId) {
                if ($this->isId($selectionId)) {
                    $chosen[(int)$optionId][(int)$selectionId] = true;
                }
            }
        }

        return $chosen;
    }

    /**
     * A stored array, or a JSON string of one; anything else is an empty array.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether a key or value is a positive integer id.
     *
     * @param mixed $value
     * @return bool
     */
    private function isId($value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int)$value > 0);
    }
}
