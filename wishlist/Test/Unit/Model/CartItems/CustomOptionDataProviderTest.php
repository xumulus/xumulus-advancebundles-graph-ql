<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Test\Unit\Model\CartItems;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Cart\Data\CartItemFactory;
use Magento\Wishlist\Model\Item;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider as CartProvider;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;
use Xumulus\AdvanceBundlesGraphQlWishlist\Model\CartItems\CustomOptionDataProvider;

class CustomOptionDataProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $buyRequest
     * @param bool $enabled
     * @return array<string, mixed>
     */
    private function execute(array $buyRequest, bool $enabled = true): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $item = $this->createStub(Item::class);
        $item->method('getBuyRequest')->willReturn(new DataObject($buyRequest));

        return (new CustomOptionDataProvider(new CustomOptionUid(), $config))->execute($item, 'XAB-BUNDLE-FIX');
    }

    public function testSavedTextsBecomeEnteredOptions(): void
    {
        $result = $this->execute([
            'bundle_option' => ['9' => '29', '10' => '34'],
            'bundle_option_qty' => ['9' => 1, '10' => '1'],
            'bundle_custom_option' => ['9' => ['29' => ['1' => '23']], '10' => ['34' => ['1' => 'Happy Birthday']]],
        ]);

        $this->assertSame(
            ['entered_options' => [
                ['uid' => base64_encode('xab-custom/9/29/1'), 'value' => '23'],
                ['uid' => base64_encode('xab-custom/10/34/1'), 'value' => 'Happy Birthday'],
            ]],
            $result
        );
    }

    public function testLegacyLocationIsRead(): void
    {
        $result = $this->execute([
            'bundle_option' => ['9' => '29', 'custom_option' => ['9' => ['29' => ['1' => 'Old']]]],
        ]);

        $this->assertSame(
            ['entered_options' => [['uid' => base64_encode('xab-custom/9/29/1'), 'value' => 'Old']]],
            $result
        );
    }

    public function testEmptyValuesAndSelectionsThatAreNotChosenAreLeftOut(): void
    {
        $result = $this->execute([
            'bundle_option' => ['9' => '29', '10' => '34'],
            'bundle_custom_option' => [
                '9' => ['29' => ['1' => '  '], '30' => ['1' => 'other item']],
                '10' => ['35' => ['1' => 'other item']],
                '11' => ['39' => ['1' => 'option not chosen']],
            ],
        ]);

        $this->assertSame([], $result);
    }

    public function testOptionsWithSeveralSelectionsAreLeftOutAsCoreDoesNotPassThemOn(): void
    {
        $result = $this->execute([
            'bundle_option' => ['11' => ['39', '40']],
            'bundle_custom_option' => ['11' => ['39' => ['1' => 'Gallery']]],
        ]);

        $this->assertSame([], $result);
    }

    public function testMalformedDataIsIgnored(): void
    {
        $this->assertSame(
            [],
            $this->execute(['bundle_option' => ['9' => '29'], 'bundle_custom_option' => ['9' => 'x']])
        );
        $this->assertSame([], $this->execute([
            'bundle_option' => ['9' => '29'],
            'bundle_custom_option' => ['9' => ['29' => ['abc' => 'x', '2' => ['nested']]]],
        ]));
        $this->assertSame([], $this->execute(['bundle_custom_option' => ['9' => ['29' => ['1' => 'x']]]]));
    }

    public function testDisabledExtensionAddsNothing(): void
    {
        $this->assertSame([], $this->execute([
            'bundle_option' => ['9' => '29'],
            'bundle_custom_option' => ['9' => ['29' => ['1' => '23']]],
        ], false));
    }

    /**
     * Regression: the wishlist cart item must reach the main module with the texts, so a required engraving that
     * was saved on the wishlist item no longer fails the move to the cart.
     */
    public function testTheCartItemCarriesTheTextsIntoTheBuyRequest(): void
    {
        $data = ['sku' => 'XAB-BUNDLE-FIX', 'quantity' => 1.0, 'selected_options' => [base64_encode('bundle/9/29/1')]];
        $data = array_merge_recursive($data, $this->execute([
            'bundle_option' => ['9' => '29'],
            'bundle_custom_option' => ['9' => ['29' => ['1' => '23']]],
        ]));
        $cartItem = (new CartItemFactory())->create($data);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        $this->assertSame(
            ['bundle_custom_option' => [9 => [29 => [1 => '23']]], CartProvider::VALUES_FLAG => true],
            (new CartProvider(new CustomOptionValues(new CustomOptionUid()), $config))->execute($cartItem)
        );
    }
}
