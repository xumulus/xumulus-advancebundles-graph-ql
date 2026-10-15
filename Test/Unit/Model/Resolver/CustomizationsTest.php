<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\CustomOption\QuoteApplier;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
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
        'option_id' => 5,
        'option_label' => 'Select Option',
        'selection_id' => 9,
        'selection_label' => 'AB Child Alpha',
        'custom_option_id' => 1,
        'title' => 'Engraving text',
        'type' => 'field',
        'value' => '23',
    ];

    /**
     * @param array<string, mixed>|null $value
     * @return mixed
     */
    private function resolve(?array $value)
    {
        $resolver = new Customizations(new QuoteApplier(new Json()), new CustomOptionUid());

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
        $this->assertNull($result[0]['option_id']);
    }

    public function testNoModel(): void
    {
        $this->assertSame([], $this->resolve(null));
        $this->assertSame([], $this->resolve(['model' => new \stdClass()]));
    }
}
