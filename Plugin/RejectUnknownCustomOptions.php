<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Plugin;

use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundles\Model\CustomOption\BuyRequestData;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

/**
 * Rejects GraphQL bundle custom option values that do not belong to the bundle being added.
 *
 * The main module's validator looks only at the chosen selections' own text custom options and ignores anything
 * else, which suits the storefront form: it posts an input for every selection, chosen or not. An API client
 * names each value explicitly, so a value that matches nothing (a uid copied from another product, or edited) is
 * an error the client should hear about instead of text that silently disappears.
 *
 * Only values sent as entered_options are checked: CustomOptionDataProvider marks those buy requests. Anything else
 * that reaches the cart in the graphql area keeps the main module's lenient handling. In particular reorderItems
 * re-adds the stored info_buyRequest, and an order placed with 1.3.x carries an input (often empty) for every
 * selection of the storefront form, chosen or not.
 *
 * Registered for the graphql area only. The failure is returned as a string, which is how core's
 * prepareForCartAdvanced() reports a problem; addProductsToCart turns it into a user error.
 */
class RejectUnknownCustomOptions
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ProductCustomOptionRepositoryInterface
     */
    private ProductCustomOptionRepositoryInterface $optionRepository;

    /**
     * Buy requests whose custom option values came from entered_options.
     *
     * @var \WeakMap<DataObject, bool>
     */
    private \WeakMap $fromEnteredOptions;

    /**
     * @param Config $config
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     */
    public function __construct(Config $config, ProductCustomOptionRepositoryInterface $optionRepository)
    {
        $this->config = $config;
        $this->optionRepository = $optionRepository;
        $this->fromEnteredOptions = new \WeakMap();
    }

    /**
     * Take the entered_options mark off the buy request, before core stores it as info_buyRequest.
     *
     * @param Type $subject
     * @param DataObject $buyRequest
     * @param Product|mixed $product core declares no type
     * @param string|null $processMode
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePrepareForCartAdvanced(
        Type $subject,
        DataObject $buyRequest,
        $product,
        $processMode = null
    ) {
        if ($buyRequest->hasData(CustomOptionDataProvider::VALUES_FLAG)) {
            if ($buyRequest->getData(CustomOptionDataProvider::VALUES_FLAG)) {
                $this->fromEnteredOptions[$buyRequest] = true;
            }
            $buyRequest->unsetData(CustomOptionDataProvider::VALUES_FLAG);
        }

        return null;
    }

    /**
     * Check every value sent as entered_options against the selections core resolved.
     *
     * @param Type $subject
     * @param array<mixed>|string $result
     * @param DataObject $buyRequest
     * @param Product|mixed $product core declares no type
     * @param string|null $processMode
     * @return array<mixed>|string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterPrepareForCartAdvanced(
        Type $subject,
        $result,
        DataObject $buyRequest,
        $product,
        $processMode = null
    ) {
        $fromEnteredOptions = isset($this->fromEnteredOptions[$buyRequest]);
        unset($this->fromEnteredOptions[$buyRequest]);
        if (!$fromEnteredOptions || !is_array($result) || !$product instanceof Product
            || !$this->config->isEnabled((int)$product->getStoreId())
        ) {
            return $result;
        }
        $values = BuyRequestData::extract($buyRequest);
        if (!$values) {
            return $result;
        }

        $selections = [];
        foreach ($result as $candidate) {
            if ($candidate !== $product && $candidate instanceof Product && $candidate->getSelectionId()) {
                $selections[(int)$candidate->getOptionId()][(int)$candidate->getSelectionId()] = $candidate;
            }
        }

        $notSelected = (string)__('A custom option was entered for a bundle item that is not selected.');
        foreach ($values as $optionId => $bySelection) {
            if (!is_array($bySelection)) {
                return $notSelected;
            }
            foreach ($bySelection as $selectionId => $byCustomOption) {
                $selection = $selections[(int)$optionId][(int)$selectionId] ?? null;
                if ($selection === null || !is_array($byCustomOption)) {
                    return $notSelected;
                }
                $textOptionIds = $this->getTextOptionIds($selection);
                foreach (array_keys($byCustomOption) as $customOptionId) {
                    if (!in_array((int)$customOptionId, $textOptionIds, true)) {
                        return (string)__(
                            'The custom option with ID "%1" is not available for "%2".',
                            $customOptionId,
                            $selection->getName()
                        );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Ids of a bundle item's text custom options, read the way the main module's validator reads them.
     *
     * @param Product $selection
     * @return int[]
     */
    private function getTextOptionIds(Product $selection): array
    {
        $options = $selection->getOptions();
        if (empty($options)) {
            if (!$selection->getData('has_options')) {
                return [];
            }
            $options = $this->optionRepository->getProductOptions($selection);
        }

        $ids = [];
        foreach ($options as $option) {
            if (in_array($option->getType(), SelectionCustomOptions::TEXT_TYPES, true)) {
                $ids[] = (int)$option->getOptionId();
            }
        }

        return $ids;
    }
}
