<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Xumulus\AdvanceBundles\Model\ResourceModel\BundleAttributes;

/**
 * The extension's settings of bundle options, loaded in one query for all options a GraphQL request asks about.
 *
 * Reads the same columns as the main module's xab_visible, xab_cms_block and xab_gallery_single extension
 * attributes (Xumulus\AdvanceBundles\Plugin\Bundle\OptionExtensionAttributes::populate()), with the same mapping.
 */
class OptionSettings implements ResetAfterRequestInterface
{
    public const VISIBLE = 'visible';
    public const CMS_BLOCK = 'cms_block';
    public const GALLERY_SINGLE = 'gallery_single';

    /**
     * @var BundleAttributes
     */
    private BundleAttributes $bundleAttributes;

    /**
     * Settings by option id; null for an option with no row.
     *
     * @var array<int, array{visible: bool, cms_block: string|null, gallery_single: bool}|null>
     */
    private array $settings = [];

    /**
     * @param BundleAttributes $bundleAttributes
     */
    public function __construct(BundleAttributes $bundleAttributes)
    {
        $this->bundleAttributes = $bundleAttributes;
    }

    /**
     * Settings of bundle options, loading only those not loaded yet.
     *
     * @param int[] $optionIds
     * @return array<int, array{visible: bool, cms_block: string|null, gallery_single: bool}> by option id; options
     *     that do not exist are left out
     */
    public function get(array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        $missing = array_values(array_diff($optionIds, array_keys($this->settings)));
        if ($missing) {
            $rows = $this->bundleAttributes->getOptionValues($missing);
            foreach ($missing as $optionId) {
                $this->settings[$optionId] = isset($rows[$optionId]) ? $this->map($rows[$optionId]) : null;
            }
        }

        $result = [];
        foreach ($optionIds as $optionId) {
            if ($this->settings[$optionId] !== null) {
                $result[$optionId] = $this->settings[$optionId];
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->settings = [];
    }

    /**
     * Settings from a stored row: an option is visible unless hide is '0'; a blank CMS block is none.
     *
     * @param array<string, mixed> $row
     * @return array{visible: bool, cms_block: string|null, gallery_single: bool}
     */
    private function map(array $row): array
    {
        $cmsBlock = trim((string)($row[BundleAttributes::COLUMN_CMS_BLOCK] ?? ''));

        return [
            self::VISIBLE => (string)($row[BundleAttributes::COLUMN_VISIBLE] ?? '') !== '0',
            self::CMS_BLOCK => $cmsBlock !== '' ? $cmsBlock : null,
            self::GALLERY_SINGLE => (bool)(int)($row[BundleAttributes::COLUMN_GALLERY_SINGLE] ?? 0),
        ];
    }
}
