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
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

/**
 * Rejects GraphQL bundle custom option values that do not belong to the bundle being added.
 *
 * The main module's validator looks only at the chosen selections' own text custom options and ignores anything
 * else, which suits the storefront form: it posts an input for every selection, chosen or not. An API client
 * names each value explicitly, so a value that matches nothing (a uid copied from another product, or edited) is
 * an error the client should hear about instead of text that silently disappears.
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
     * @param Config $config
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     */
    public function __construct(Config $config, ProductCustomOptionRepositoryInterface $optionRepository)
    {
        $this->config = $config;
        $this->optionRepository = $optionRepository;
    }

    /**
     * Check every posted value against the selections core resolved.
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
        if (!is_array($result) || !$product instanceof Product
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
