<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Widget\Model\Template\FilterEmulate;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Xumulus\AdvanceBundlesGraphQl\Model\CmsBlockRenderer;

class CmsBlockRendererTest extends TestCase
{
    /**
     * Stubs by default; a test that sets expectations replaces one with a mock before building the renderer.
     *
     * @var Collection&Stub
     */
    private $collection;

    /**
     * @var CollectionFactory&Stub
     */
    private $collectionFactory;

    /**
     * @var FilterEmulate&Stub
     */
    private $filter;

    /**
     * @var LoggerInterface&Stub
     */
    private $logger;

    protected function setUp(): void
    {
        $this->collection = $this->createStub(Collection::class);
        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->filter = $this->createStub(FilterEmulate::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    /**
     * Renderer over the current collaborators; the collection returns the blocks.
     *
     * @param Block[] $blocks
     * @return CmsBlockRenderer
     */
    private function createRenderer(array $blocks = []): CmsBlockRenderer
    {
        $this->collection->method('addStoreFilter')->willReturnSelf();
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getItems')->willReturn($blocks);
        $this->collectionFactory->method('create')->willReturn($this->collection);
        $this->filter->method('filterDirective')->willReturnCallback(
            static fn (string $content): string => '<rendered>' . $content
        );

        return new CmsBlockRenderer($this->collectionFactory, $this->filter, $this->logger);
    }

    /**
     * CMS block carrying only data.
     *
     * @param array<string, mixed> $data
     * @return Block
     */
    private function buildBlock(array $data): Block
    {
        /** @var Block $block */
        $block = (new \ReflectionClass(Block::class))->newInstanceWithoutConstructor();
        $block->setData($data);

        return $block;
    }

    public function testRendersBlocksByIdentifierAndByNumericIdForTheStore(): void
    {
        $this->filter = $this->createMock(FilterEmulate::class);
        $this->filter->expects($this->atLeastOnce())->method('setStoreId')->with(1);
        $renderer = $this->createRenderer([
            $this->buildBlock(['block_id' => 18, 'identifier' => 'xab-option-note', 'content' => 'A', 'store_id' => [0]]),
            $this->buildBlock(['block_id' => 20, 'identifier' => 'other', 'content' => 'B', 'store_id' => [0]]),
        ]);

        $this->assertSame(
            ['xab-option-note' => '<rendered>A', '20' => '<rendered>B', 'missing' => null],
            $renderer->render(['xab-option-note', ' 20 ', 'missing', '', 'xab-option-note'], 1)
        );
    }

    public function testFiltersOnActiveBlocksOfTheStore(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->expects($this->once())->method('addStoreFilter')->with(3);
        $conditions = [];
        $this->collection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function ($field, $condition) use (&$conditions) {
                $conditions[] = [$field, $condition];

                return $this->collection;
            });

        $this->createRenderer()->render(['note', '7'], 3);

        $this->assertSame(
            [
                ['is_active', 1],
                [['identifier', 'block_id'], [['in' => ['note']], ['in' => [7]]]],
            ],
            $conditions
        );
    }

    public function testBlockAssignedToTheStoreWinsOverOneForAllStores(): void
    {
        $renderer = $this->createRenderer([
            $this->buildBlock(['block_id' => 30, 'identifier' => 'note', 'content' => 'store', 'store_id' => [2]]),
            $this->buildBlock(['block_id' => 31, 'identifier' => 'note', 'content' => 'all', 'store_id' => [0]]),
        ]);

        $this->assertSame(['note' => '<rendered>store'], $renderer->render(['note'], 2));
    }

    public function testLoadsEachReferenceOncePerStore(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(fn () => $this->collection);
        $renderer = $this->createRenderer();

        $renderer->render(['a', 'b'], 1);
        $renderer->render(['b', 'a'], 1);
        $renderer->render(['a'], 2);
        $renderer->render([], 1);
    }

    public function testContentThatCannotBeRenderedGivesNullAndIsLogged(): void
    {
        $this->filter->method('filterDirective')->willThrowException(new \RuntimeException('bad widget'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())->method('error');
        $renderer = $this->createRenderer([
            $this->buildBlock(['block_id' => 18, 'identifier' => 'broken', 'content' => '{{widget}}', 'store_id' => [0]]),
        ]);

        $this->assertSame(['broken' => null], $renderer->render(['broken'], 1));
    }
}
