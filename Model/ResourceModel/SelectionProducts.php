<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Xumulus\AdvanceBundles\Model\ResourceModel\BundleAttributes;

/**
 * Looks up the bundle option and child product of bundle selections.
 */
class SelectionProducts
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Option and product of each selection; selections that no longer exist are left out.
     *
     * @param int[] $selectionIds
     * @return array<int, array{option_id: int, product_id: int}> by selection id
     */
    public function get(array $selectionIds): array
    {
        $selectionIds = array_values(array_unique(array_filter(array_map('intval', $selectionIds))));
        if (!$selectionIds) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(BundleAttributes::SELECTION_TABLE),
                ['selection_id', 'option_id', 'product_id']
            )
            ->where('selection_id IN (?)', $selectionIds);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int)$row['selection_id']] = [
                'option_id' => (int)$row['option_id'],
                'product_id' => (int)$row['product_id'],
            ];
        }

        return $rows;
    }
}
