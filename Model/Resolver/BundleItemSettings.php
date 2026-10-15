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
use Xumulus\AdvanceBundlesGraphQl\Model\CmsBlockRenderer;
use Xumulus\AdvanceBundlesGraphQl\Model\OptionSettings;

/**
 * BundleItem.xab_visible, xab_cms_block, xab_cms_block_html and xab_gallery_single.
 *
 * Resolves every bundle item of the request at once: one query for the settings of all options (shared by the four
 * fields) and one for the CMS blocks they reference.
 */
class BundleItemSettings implements BatchResolverInterface
{
    /**
     * @var OptionSettings
     */
    private OptionSettings $optionSettings;

    /**
     * @var CmsBlockRenderer
     */
    private CmsBlockRenderer $cmsBlockRenderer;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param OptionSettings $optionSettings
     * @param CmsBlockRenderer $cmsBlockRenderer
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        OptionSettings $optionSettings,
        CmsBlockRenderer $cmsBlockRenderer,
        Config $config,
        StoreManagerInterface $storeManager
    ) {
        $this->optionSettings = $optionSettings;
        $this->cmsBlockRenderer = $cmsBlockRenderer;
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

        $settings = $this->optionSettings->get(array_map([$this, 'getOptionId'], $requests));
        $fieldName = $field->getName();
        $html = [];
        if ($fieldName === 'xab_cms_block_html') {
            $html = $this->cmsBlockRenderer->render(
                array_filter(array_column($settings, OptionSettings::CMS_BLOCK)),
                $storeId
            );
        }

        foreach ($requests as $request) {
            $optionSettings = $settings[$this->getOptionId($request)] ?? null;
            $response->addResponse($request, $this->getFieldValue($fieldName, $optionSettings, $html));
        }

        return $response;
    }

    /**
     * Bundle option id of a BundleItem.
     *
     * @param BatchRequestItemInterface $request
     * @return int
     */
    private function getOptionId(BatchRequestItemInterface $request): int
    {
        $value = $request->getValue() ?? [];

        return (int)($value['option_id'] ?? 0);
    }

    /**
     * Value of one field for one option.
     *
     * @param string $fieldName
     * @param array<string,bool|string|null>|null $settings see OptionSettings
     * @param array<string,string|null> $html rendered CMS blocks by reference
     * @return bool|string|null
     */
    private function getFieldValue(string $fieldName, ?array $settings, array $html)
    {
        if ($settings === null) {
            return null;
        }

        switch ($fieldName) {
            case 'xab_visible':
                return $settings[OptionSettings::VISIBLE];
            case 'xab_cms_block':
                return $settings[OptionSettings::CMS_BLOCK];
            case 'xab_cms_block_html':
                $reference = $settings[OptionSettings::CMS_BLOCK];

                return $reference !== null ? ($html[$reference] ?? null) : null;
            case 'xab_gallery_single':
                return $settings[OptionSettings::GALLERY_SINGLE];
            default:
                return null;
        }
    }
}
