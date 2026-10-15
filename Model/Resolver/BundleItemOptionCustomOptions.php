<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Store\Model\StoreManagerInterface;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

/**
 * BundleItemOption.xab_custom_option_required and xab_custom_options.
 *
 * Resolves every bundle selection of the request at once (see SelectionCustomOptions).
 */
class BundleItemOptionCustomOptions implements BatchResolverInterface
{
    /**
     * @var SelectionCustomOptions
     */
    private SelectionCustomOptions $selectionCustomOptions;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param SelectionCustomOptions $selectionCustomOptions
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        SelectionCustomOptions $selectionCustomOptions,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->selectionCustomOptions = $selectionCustomOptions;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritDoc
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $response = new BatchResponse();
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            foreach ($requests as $request) {
                $response->addResponse($request, null);
            }

            return $response;
        }

        $selections = [];
        foreach ($requests as $request) {
            $selection = $this->getSelection($request);
            if ($selection !== null) {
                $selections[$selection['selection_id']] = $selection;
            }
        }
        $resolved = $this->selectionCustomOptions->get($selections, $storeId);
        $required = $field->getName() === 'xab_custom_option_required';

        foreach ($requests as $request) {
            $selection = $this->getSelection($request);
            $data = $selection !== null ? ($resolved[$selection['selection_id']] ?? null) : null;
            if ($required) {
                $response->addResponse($request, $data !== null ? $data['required'] : null);
            } else {
                $response->addResponse($request, $data !== null ? $data['options'] : null);
            }
        }

        return $response;
    }

    /**
     * Ids of the bundle selection a BundleItemOption stands for, null when they are missing.
     *
     * @param BatchRequestItemInterface $request
     * @return array{selection_id: int, option_id: int, product_id: int}|null
     */
    private function getSelection(BatchRequestItemInterface $request): ?array
    {
        $value = $request->getValue() ?? [];
        $selectionId = (int)($value['selection_id'] ?? 0);
        $optionId = (int)($value['option_id'] ?? 0);
        $productId = (int)($value['product_id'] ?? $value['entity_id'] ?? 0);
        if (!$selectionId || !$optionId || !$productId) {
            return null;
        }

        return ['selection_id' => $selectionId, 'option_id' => $optionId, 'product_id' => $productId];
    }
}
