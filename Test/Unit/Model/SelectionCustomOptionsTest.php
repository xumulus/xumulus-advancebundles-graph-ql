<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use Magento\Catalog\Model\ResourceModel\Product\Option\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Option\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\ResourceModel\BundleAttributes;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

class SelectionCustomOptionsTest extends TestCase
{
    /**
     * @var CollectionFactory&Stub
     */
    private $collectionFactory;

    /**
     * @var BundleAttributes&Stub a mock in the tests that set expectations
     */
    private $attributes;

    /**
     * Product ids each created collection was filtered on.
     *
     * @var array<int, int[]>
     */
    private $filteredProductIds = [];

    /**
     * @var int
     */
    private $overrideLoads = 0;

    /**
     * @var array<int, DataObject[]> custom options by product id
     */
    private $options = [];

    protected function setUp(): void
    {
        $this->options = [
            2041 => [
                new DataObject([
                    'option_id' => 1, 'title' => 'Engraving text', 'type' => 'field', 'is_require' => '0',
                    'max_characters' => '30', 'price' => '0.000000', 'price_type' => 'fixed', 'sort_order' => '1',
                ]),
                new DataObject([
                    'option_id' => 3, 'title' => 'Gift note', 'type' => 'area', 'is_require' => '1',
                    'max_characters' => null, 'price' => '10.5', 'price_type' => 'percent', 'sort_order' => '2',
                ]),
            ],
            2042 => [],
        ];

        $this->collectionFactory = $this->createStub(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturnCallback(function () {
            $index = count($this->filteredProductIds);
            $this->filteredProductIds[$index] = [];
            $select = $this->createStub(Select::class);
            $select->method('where')->willReturnCallback(function ($condition, $ids) use ($select, $index) {
                $this->assertSame('cpe.entity_id IN (?)', $condition);
                $this->filteredProductIds[$index] = $ids;

                return $select;
            });
            $select->method('columns')->willReturnSelf();

            $collection = $this->createStub(Collection::class);
            $collection->method('getSelect')->willReturn($select);
            $collection->method('addFieldToFilter')->willReturnSelf();
            $collection->method('addTitleToResult')->willReturnSelf();
            $collection->method('addPriceToResult')->willReturnSelf();
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('getItems')->willReturnCallback(function () use ($index) {
                $items = [];
                foreach ($this->filteredProductIds[$index] as $productId) {
                    foreach ($this->options[$productId] ?? [] as $option) {
                        $items[] = (clone $option)->setData('xab_product_id', (string)$productId);
                    }
                }

                return $items;
            });

            return $collection;
        });

        $this->attributes = $this->createStub(BundleAttributes::class);
    }

    private function createService(): SelectionCustomOptions
    {
        return new SelectionCustomOptions($this->collectionFactory, $this->attributes, new CustomOptionUid());
    }

    public function testResolvesOptionsAndRequiredFlagsPerSelection(): void
    {
        // Selection 29 forces its custom options to be required, 30 makes them optional, 9 inherits.
        $this->attributes->method('getCustomOptionRequired')->willReturn([29 => 1, 30 => 0]);

        $result = $this->createService()->get(
            [
                9 => ['option_id' => 5, 'product_id' => 2041],
                29 => ['option_id' => 9, 'product_id' => 2041],
                30 => ['option_id' => 9, 'product_id' => 2041],
                10 => ['option_id' => 5, 'product_id' => 2042],
            ],
            1
        );

        $this->assertSame(
            [
                'option_id' => 1,
                'title' => 'Engraving text',
                'type' => 'field',
                'max_characters' => 30,
                'price' => 0.0,
                'price_type' => 'FIXED',
                'sort_order' => 1,
                'required' => false,
                'uid' => base64_encode('xab-custom/5/9/1'),
            ],
            $result[9]['options'][0]
        );
        $this->assertSame(
            ['option_id' => 3, 'max_characters' => 0, 'price' => 10.5, 'price_type' => 'PERCENT', 'required' => true],
            array_intersect_key(
                $result[9]['options'][1],
                array_flip(['option_id', 'max_characters', 'price', 'price_type', 'required'])
            )
        );
        $this->assertTrue($result[9]['required'], 'inherits: one of the item\'s options is required');

        $this->assertTrue($result[29]['required']);
        $this->assertSame([true, true], array_column($result[29]['options'], 'required'));
        $this->assertSame(base64_encode('xab-custom/9/29/3'), $result[29]['options'][1]['uid']);

        $this->assertFalse($result[30]['required']);
        $this->assertSame([false, false], array_column($result[30]['options'], 'required'));

        $this->assertSame(['required' => false, 'options' => []], $result[10]);
    }

    public function testLoadsEachProductAndSelectionOnce(): void
    {
        $this->attributes = $this->createMock(BundleAttributes::class);
        $this->attributes->expects($this->exactly(2))
            ->method('getCustomOptionRequired')
            ->willReturnCallback(function (array $ids): array {
                $this->assertSame(++$this->overrideLoads === 1 ? [9, 10] : [29], $ids);

                return [];
            });
        $service = $this->createService();

        $service->get(
            [9 => ['option_id' => 5, 'product_id' => 2041], 10 => ['option_id' => 5, 'product_id' => 2042]],
            1
        );
        $service->get(
            [9 => ['option_id' => 5, 'product_id' => 2041], 29 => ['option_id' => 9, 'product_id' => 2041]],
            1
        );

        $this->assertSame([[2041, 2042]], $this->filteredProductIds, 'one query for both products, none after');
    }

    public function testProductOptionsAreLoadedPerStore(): void
    {
        $this->attributes->method('getCustomOptionRequired')->willReturn([]);
        $service = $this->createService();

        $service->get([9 => ['option_id' => 5, 'product_id' => 2041]], 1);
        $service->get([9 => ['option_id' => 5, 'product_id' => 2041]], 2);

        $this->assertSame([[2041], [2041]], $this->filteredProductIds);
    }
}
