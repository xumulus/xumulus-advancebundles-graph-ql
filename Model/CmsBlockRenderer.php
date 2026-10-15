<?php
/**
 * Copyright © Xumulus. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Model;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Widget\Model\Template\FilterEmulate;
use Psr\Log\LoggerInterface;

/**
 * Renders the CMS blocks attached to bundle options, all of a request's blocks with one query.
 *
 * A block is looked up the way the storefront's Magento\Cms\Block\Block does it: a numeric value is a block id,
 * anything else an identifier. Only active blocks assigned to the store (or to all stores) are rendered, and a
 * block assigned to the store wins over one with the same identifier assigned to all stores. Content goes through
 * the same widget filter as CmsGraphQl's cmsBlocks query, in the frontend area.
 */
class CmsBlockRenderer implements ResetAfterRequestInterface
{
    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var FilterEmulate
     */
    private FilterEmulate $filter;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Rendered HTML by store id and stored value; null when there is no block to render.
     *
     * @var array<int, array<string, string|null>>
     */
    private array $rendered = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param FilterEmulate $filter
     * @param LoggerInterface $logger
     */
    public function __construct(CollectionFactory $collectionFactory, FilterEmulate $filter, LoggerInterface $logger)
    {
        $this->collectionFactory = $collectionFactory;
        $this->filter = $filter;
        $this->logger = $logger;
    }

    /**
     * Rendered blocks for stored block references.
     *
     * @param string[] $references block identifiers or numeric block ids, as stored on the options
     * @param int $storeId
     * @return array<string, string|null> by reference; null when no active block is assigned to the store
     */
    public function render(array $references, int $storeId): array
    {
        $references = array_values(array_unique(array_filter(array_map('trim', $references), 'strlen')));
        $missing = array_values(array_diff($references, array_keys($this->rendered[$storeId] ?? [])));
        if ($missing) {
            $blocks = $this->loadBlocks($missing, $storeId);
            foreach ($missing as $reference) {
                $block = $blocks[$reference] ?? null;
                $this->rendered[$storeId][$reference] = $block !== null ? $this->filter($block, $storeId) : null;
            }
        }

        $result = [];
        foreach ($references as $reference) {
            $result[$reference] = $this->rendered[$storeId][$reference];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->rendered = [];
    }

    /**
     * Active blocks of the store matching the references.
     *
     * @param string[] $references
     * @param int $storeId
     * @return array<string, BlockInterface> by reference
     */
    private function loadBlocks(array $references, int $storeId): array
    {
        $ids = [];
        $identifiers = [];
        foreach ($references as $reference) {
            if (ctype_digit($reference)) {
                $ids[] = (int)$reference;
            } else {
                $identifiers[] = $reference;
            }
        }

        $collection = $this->collectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addFieldToFilter(BlockInterface::IS_ACTIVE, 1);
        $collection->addFieldToFilter(
            [BlockInterface::IDENTIFIER, BlockInterface::BLOCK_ID],
            [['in' => $identifiers ?: ['']], ['in' => $ids ?: [0]]]
        );

        $byId = [];
        $byIdentifier = [];
        /** @var \Magento\Cms\Model\Block $block */
        foreach ($collection->getItems() as $block) {
            $byId[(string)$block->getId()] = $block;
            $identifier = (string)$block->getIdentifier();
            if (!isset($byIdentifier[$identifier]) || $this->isAssignedTo($block, $storeId)) {
                $byIdentifier[$identifier] = $block;
            }
        }

        $blocks = [];
        foreach ($references as $reference) {
            $block = ctype_digit($reference) ? ($byId[$reference] ?? null) : ($byIdentifier[$reference] ?? null);
            if ($block !== null) {
                $blocks[$reference] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Whether a block is assigned to the store itself, not only to all stores.
     *
     * @param \Magento\Cms\Model\Block $block
     * @param int $storeId
     * @return bool
     */
    private function isAssignedTo($block, int $storeId): bool
    {
        $stores = array_map('intval', (array)$block->getData('store_id'));

        return in_array($storeId, $stores, true);
    }

    /**
     * Block content with directives and widgets processed; null when the content cannot be rendered.
     *
     * @param BlockInterface $block
     * @param int $storeId
     * @return string|null
     */
    private function filter(BlockInterface $block, int $storeId): ?string
    {
        try {
            $this->filter->setStoreId($storeId);

            return $this->filter->filterDirective((string)$block->getContent());
        } catch (\Exception $e) {
            $message = sprintf('CMS block "%s" could not be rendered.', $block->getIdentifier());
            $this->logger->error('Xumulus_AdvanceBundlesGraphQl: ' . $message, ['exception' => $e]);

            return null;
        }
    }
}
