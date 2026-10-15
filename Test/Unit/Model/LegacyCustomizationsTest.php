<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\Option;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\LegacyCustomizations;
use Xumulus\AdvanceBundlesGraphQl\Model\ResourceModel\SelectionProducts;
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

/**
 * Items shaped like the ones 1.3.x left on the QA store (cart qLIHN6Ej..., orders 000000003 and 000000004).
 */
class LegacyCustomizationsTest extends TestCase
{
    private const ADDITIONAL = [
        ['label' => 'Custom Options', 'value' => 'Customizations'],
        ['label' => 'AB Child Alpha [Radio Option]', 'value' => 'ENGRAVED-2'],
    ];

    private const ENGRAVING = [
        'option_id' => 1,
        'title' => 'Engraving text',
        'type' => 'field',
        'is_require' => false,
        'max_characters' => 30,
        'price' => 0.0,
        'price_type' => 'FIXED',
        'sort_order' => 1,
    ];

    /**
     * @var LegacyCustomizations
     */
    private $legacy;

    /**
     * Build the reader on a selection lookup; a stub returning the rows unless a mock is given.
     *
     * @param array<int, array{option_id: int, product_id: int}> $rows
     * @param SelectionProducts|null $selectionProducts
     * @return void
     */
    private function createLegacy(array $rows = [], ?SelectionProducts $selectionProducts = null): void
    {
        if ($selectionProducts === null) {
            $selectionProducts = $this->createStub(SelectionProducts::class);
            $selectionProducts->method('get')->willReturn($rows);
        }
        $customOptions = $this->createStub(SelectionCustomOptions::class);
        $customOptions->method('getProductOptions')->willReturnCallback(
            static fn (array $productIds) => in_array(2041, $productIds, true) ? [2041 => [self::ENGRAVING]] : []
        );
        $this->legacy = new LegacyCustomizations(new Json(), $selectionProducts, $customOptions);
    }

    /**
     * Instance of a model class with data and no collaborators.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @return T
     */
    private function build(string $class, array $data)
    {
        $object = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $object->setData($data);

        return $object;
    }

    /**
     * Quote item with options by code.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options code => value, arrays are JSON encoded
     * @return QuoteItem
     */
    private function quoteItem(array $data, array $options): QuoteItem
    {
        $item = $this->build(QuoteItem::class, $data);
        $byCode = [];
        foreach ($options as $code => $value) {
            $byCode[$code] = $this->build(Option::class, [
                'code' => $code,
                'value' => is_array($value) ? json_encode($value) : $value,
            ]);
        }
        $property = new \ReflectionProperty(QuoteItem::class, '_optionsByCode');
        $property->setValue($item, $byCode);

        return $item;
    }

    /**
     * Quote child item for a selection.
     *
     * @param int $optionId
     * @param string $label
     * @param int $selectionId
     * @return QuoteItem
     */
    private function quoteChild(int $optionId, string $label, int $selectionId): QuoteItem
    {
        return $this->quoteItem(['product_id' => 2041, 'name' => 'AB Child Alpha'], [
            'selection_id' => (string)$selectionId,
            'bundle_selection_attributes' => ['price' => 0, 'qty' => 1, 'option_label' => $label,
                'option_id' => (string)$optionId],
        ]);
    }

    /**
     * Order child item for an option.
     *
     * @param int $optionId
     * @param string $label
     * @param int $productId
     * @return OrderItem
     */
    private function orderChild(int $optionId, string $label, int $productId = 2041): OrderItem
    {
        return $this->build(OrderItem::class, [
            'product_id' => $productId,
            'name' => $productId === 2041 ? 'AB Child Alpha' : 'AB Child Bravo',
            'product_options' => ['bundle_selection_attributes' => json_encode(
                ['price' => 0, 'qty' => 1, 'option_label' => $label, 'option_id' => (string)$optionId]
            )],
        ]);
    }

    /**
     * Read, register and resolve one item.
     *
     * @param mixed $item
     * @return list<array<string, mixed>>
     */
    private function entries($item): array
    {
        $request = $this->legacy->read($item);
        if ($request === null) {
            return [];
        }
        $this->legacy->register($request);

        return $this->legacy->getEntries($request);
    }

    /**
     * The fixed-price bundle as cart item 10 holds it.
     *
     * @return QuoteItem
     */
    private function legacyCartItem(): QuoteItem
    {
        $item = $this->quoteItem(['product_id' => 2047, 'store_id' => 1], [
            'additional_options' => self::ADDITIONAL,
            'info_buyRequest' => [
                'bundle_option' => [
                    '9' => '29',
                    '10' => '34',
                    'custom_option' => [
                        '9' => ['30' => ['1' => 'not chosen']],
                        '10' => ['34' => ['1' => 'ENGRAVED-2']],
                        '11' => ['39' => ['1' => " ENGRAVED-3\r\nline 2 "]],
                        '12' => ['44' => ['1' => '']],
                    ],
                    '11' => ['2041' => '39'],
                    '12' => ['44'],
                ],
            ],
        ]);
        $item->addChild($this->quoteChild(9, 'Select Option', 29));
        $item->addChild($this->quoteChild(10, 'Radio Option', 34));
        $item->addChild($this->quoteChild(11, 'Checkbox Option', 39));
        $item->addChild($this->quoteChild(12, 'Multi Option', 44));

        return $item;
    }

    public function testCartItemFromTheLegacyLocation(): void
    {
        $this->createLegacy();

        $this->assertSame(
            [
                [
                    'option_id' => 10,
                    'option_title' => 'Radio Option',
                    'selection_id' => 34,
                    'product_id' => 2041,
                    'product_name' => 'AB Child Alpha',
                    'custom_option_id' => 1,
                    'custom_option_title' => 'Engraving text',
                    'type' => 'field',
                    'value' => 'ENGRAVED-2',
                ],
                [
                    'option_id' => 11,
                    'option_title' => 'Checkbox Option',
                    'selection_id' => 39,
                    'product_id' => 2041,
                    'product_name' => 'AB Child Alpha',
                    'custom_option_id' => 1,
                    'custom_option_title' => 'Engraving text',
                    'type' => 'field',
                    'value' => "ENGRAVED-3\nline 2",
                ],
            ],
            $this->entries($this->legacyCartItem())
        );
    }

    public function testCartItemWithoutAdditionalOptionsIsNotRead(): void
    {
        $this->createLegacy();
        $item = $this->quoteItem(['store_id' => 1], [
            'info_buyRequest' => ['bundle_custom_option' => ['10' => ['34' => ['1' => 'never applied']]]],
        ]);
        $item->addChild($this->quoteChild(10, 'Radio Option', 34));

        $this->assertNull($this->legacy->read($item));
    }

    public function testCartItemWithOnlyEmptyInputsHasNothing(): void
    {
        $this->createLegacy();
        $item = $this->quoteItem(['store_id' => 1], [
            'additional_options' => [['label' => 'Custom Options', 'value' => 'Customizations']],
            'info_buyRequest' => ['bundle_option' => ['6' => '14', 'custom_option' => ['6' => ['14' => ['1' => '']]]]],
        ]);

        $this->assertNull($this->legacy->read($item));
    }

    public function testOrderItemMatchesChildrenThroughTheSelectionProducts(): void
    {
        $selectionProducts = $this->createMock(SelectionProducts::class);
        $selectionProducts->expects($this->once())->method('get')
            ->with($this->equalTo([29, 40, 39]))
            ->willReturn([
                29 => ['option_id' => 9, 'product_id' => 2041],
                39 => ['option_id' => 11, 'product_id' => 2041],
                40 => ['option_id' => 11, 'product_id' => 2042],
            ]);
        $this->createLegacy([], $selectionProducts);
        $item = $this->build(OrderItem::class, [
            'store_id' => 1,
            'product_options' => [
                'additional_options' => self::ADDITIONAL,
                'bundle_options' => ['9' => ['option_id' => '9', 'label' => 'Select Option (ordered)']],
                'info_buyRequest' => [
                    'bundle_option' => ['9' => '29', '11' => ['2041' => '39', '2042' => '40']],
                    'bundle_custom_option' => [
                        '9' => ['29' => ['1' => 'Admin 23']],
                        '11' => ['40' => ['5' => 'Bravo text'], '39' => ['1' => '23']],
                    ],
                ],
            ],
        ]);
        $item->addChildItem($this->orderChild(9, 'Select Option'));
        $item->addChildItem($this->orderChild(11, 'Checkbox Option'));
        $item->addChildItem($this->orderChild(11, 'Checkbox Option', 2042));

        $entries = $this->entries($item);

        $this->assertSame(
            [
                [9, 29, 'Admin 23', 'AB Child Alpha', 'Engraving text'],
                [11, 39, '23', 'AB Child Alpha', 'Engraving text'],
                [11, 40, 'Bravo text', 'AB Child Bravo', null],
            ],
            array_map(
                static fn (array $e) => [$e['option_id'], $e['selection_id'], $e['value'], $e['product_name'],
                    $e['custom_option_title']],
                $entries
            )
        );
        $this->assertSame('Select Option', $entries[0]['option_title']);
    }

    public function testOrderItemWhoseSelectionWasDeletedUsesTheOptionsOnlyChild(): void
    {
        $this->createLegacy();
        $item = $this->build(OrderItem::class, [
            'store_id' => 1,
            'product_options' => [
                'additional_options' => self::ADDITIONAL,
                'info_buyRequest' => [
                    'bundle_option' => ['9' => '29'],
                    'bundle_custom_option' => ['9' => ['29' => ['1' => 'ORDER-ENGRAVING-1']]],
                ],
            ],
        ]);
        $item->addChildItem($this->orderChild(9, 'Select Option'));

        $entries = $this->entries($item);

        $this->assertCount(1, $entries);
        $this->assertSame('AB Child Alpha', $entries[0]['product_name']);
        $this->assertSame('ORDER-ENGRAVING-1', $entries[0]['value']);
    }

    public function testItemWithoutChildrenKeepsTheChosenSelectionsTexts(): void
    {
        $this->createLegacy([29 => ['option_id' => 9, 'product_id' => 2041]]);
        $item = $this->build(OrderItem::class, [
            'store_id' => 1,
            'product_options' => [
                'additional_options' => self::ADDITIONAL,
                'info_buyRequest' => [
                    'bundle_option' => ['9' => '29'],
                    'bundle_custom_option' => ['9' => ['29' => ['1' => 'kept'], '30' => ['1' => 'not chosen']]],
                ],
            ],
        ]);

        $entries = $this->entries($item);

        $this->assertSame(['kept'], array_column($entries, 'value'));
        $this->assertSame('Engraving text', $entries[0]['custom_option_title']);
        $this->assertNull($entries[0]['product_name']);
    }

    public function testRequestsRegisteredTogetherAreLoadedTogether(): void
    {
        $selectionProducts = $this->createMock(SelectionProducts::class);
        $selectionProducts->expects($this->once())->method('get')->willReturn([]);
        $this->createLegacy([], $selectionProducts);
        $first = $this->legacy->read($this->legacyCartItem());
        $second = $this->legacy->read($this->legacyCartItem());
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->legacy->register($first);
        $this->legacy->register($second);

        $this->assertCount(2, $this->legacy->getEntries($first));
        $this->assertCount(2, $this->legacy->getEntries($second));
    }

    public function testOtherModelsAreNotRead(): void
    {
        $this->createLegacy();
        $this->assertNull($this->legacy->read(new \stdClass()));
        $this->assertNull($this->legacy->read(null));
    }
}
