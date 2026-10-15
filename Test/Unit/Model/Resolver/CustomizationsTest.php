<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\CustomOption\QuoteApplier;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\LegacyCustomizations;
use Xumulus\AdvanceBundlesGraphQl\Model\Resolver\Customizations;

class CustomizationsTest extends TestCase
{
    private const ENTRY = [
        'option_id' => 5,
        'option_title' => 'Select Option',
        'selection_id' => 9,
        'product_id' => 2041,
        'product_name' => 'AB Child Alpha',
        'custom_option_id' => 1,
        'custom_option_title' => 'Engraving text',
        'type' => 'field',
        'value' => '23',
    ];

    private const EXPECTED = [
        'uid' => 'eGFiLWN1c3RvbS81LzkvMQ==',
        'bundle_option_id' => 5,
        'option_label' => 'Select Option',
        'bundle_selection_id' => 9,
        'selection_label' => 'AB Child Alpha',
        'custom_option_id' => 1,
        'title' => 'Engraving text',
        'type' => 'field',
        'value' => '23',
    ];

    /**
     * @param array<string, mixed>|null $value
     * @param LegacyCustomizations|null $legacy
     * @return mixed
     */
    private function resolve(?array $value, ?LegacyCustomizations $legacy = null)
    {
        if ($legacy === null) {
            $legacy = $this->createStub(LegacyCustomizations::class);
            $legacy->method('read')->willReturn(null);
        }
        // Resolve deferred values straight away.
        $valueFactory = $this->createStub(ValueFactory::class);
        $valueFactory->method('create')->willReturnCallback(static fn (callable $callback) => $callback());
        $resolver = new Customizations(new QuoteApplier(new Json()), new CustomOptionUid(), $legacy, $valueFactory);

        return $resolver->resolve(
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            $value
        );
    }

    public function testReadsTheQuoteItemOption(): void
    {
        $item = $this->createStub(Item::class);
        $option = new \Magento\Framework\DataObject(['value' => json_encode([self::ENTRY, 'junk'])]);
        $item->method('getOptionByCode')->willReturnMap([['xab_custom_options', $option]]);

        $this->assertSame([self::EXPECTED], $this->resolve(['model' => $item]));
    }

    public function testQuoteItemWithoutCustomizations(): void
    {
        $item = $this->createStub(Item::class);
        $item->method('getOptionByCode')->willReturn(null);

        $this->assertSame([], $this->resolve(['model' => $item]));
    }

    public function testReadsTheOrderItemProductOption(): void
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getProductOptions')
            ->willReturn(['xab_custom_options' => [self::ENTRY], 'info_buyRequest' => []]);

        $this->assertSame([self::EXPECTED], $this->resolve(['model' => $item]));
    }

    public function testOrderItemWithoutCustomizations(): void
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getProductOptions')->willReturn(null);

        $this->assertSame([], $this->resolve(['model' => $item]));
    }

    public function testIncompleteEntriesKeepTheirValue(): void
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getProductOptions')->willReturn(['xab_custom_options' => [['value' => 'Hi']]]);

        $result = $this->resolve(['model' => $item]);

        $this->assertSame('Hi', $result[0]['value']);
        $this->assertNull($result[0]['option_label']);
        $this->assertNull($result[0]['bundle_option_id']);
    }

    public function testNoModel(): void
    {
        $this->assertSame([], $this->resolve(null));
        $this->assertSame([], $this->resolve(['model' => new \stdClass()]));
    }

    /**
     * Regression: items without the xab_custom_options option (1.3.x carts and orders) are read back from their
     * buy request instead of showing no customizations.
     */
    public function testItemWithoutStoredEntriesFallsBackToTheBuyRequest(): void
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getProductOptions')->willReturn(['additional_options' => [['label' => 'x', 'value' => 'y']]]);
        $request = ['store_id' => 1, 'values' => [[5, 9, 1, '23']], 'chosen' => [], 'children' => []];
        $legacy = $this->createMock(LegacyCustomizations::class);
        $legacy->expects($this->once())->method('read')->with($item)->willReturn($request);
        $legacy->expects($this->once())->method('register')->with($request);
        $legacy->expects($this->once())->method('getEntries')->with($request)->willReturn([self::ENTRY]);

        $this->assertSame([self::EXPECTED], $this->resolve(['model' => $item], $legacy));
    }

    public function testStoredEntriesWinOverTheBuyRequest(): void
    {
        $item = $this->createStub(OrderItem::class);
        $item->method('getProductOptions')->willReturn(['xab_custom_options' => [self::ENTRY]]);
        $legacy = $this->createMock(LegacyCustomizations::class);
        $legacy->expects($this->never())->method('read');

        $this->assertSame([self::EXPECTED], $this->resolve(['model' => $item], $legacy));
    }
}
