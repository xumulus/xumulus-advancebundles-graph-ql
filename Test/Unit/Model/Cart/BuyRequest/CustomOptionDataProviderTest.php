<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Cart\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Cart\Data\CartItem;
use Magento\Quote\Model\Cart\Data\EnteredOption;
use Magento\Quote\Model\Cart\Data\SelectedOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;

class CustomOptionDataProviderTest extends TestCase
{
    /**
     * Build the provider with the extension enabled or disabled.
     *
     * @param bool $enabled
     * @param bool $enforceRequired
     * @return CustomOptionDataProvider
     */
    private function createProvider(bool $enabled = true, bool $enforceRequired = true): CustomOptionDataProvider
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new CustomOptionDataProvider(new CustomOptionUid(), $config, $enforceRequired);
    }

    /**
     * Cart item with the given decoded selected option uids and [decoded uid, value] entered options.
     *
     * @param string[] $selected
     * @param array<array{string, string}> $entered
     * @param string $sku
     * @return CartItem
     */
    private function createCartItem(array $selected, array $entered, string $sku = 'XAB-BUNDLE-DYN'): CartItem
    {
        return new CartItem(
            $sku,
            1.0,
            null,
            array_map(static fn (string $uid) => new SelectedOption(base64_encode($uid)), $selected),
            array_map(static fn (array $option) => new EnteredOption(base64_encode($option[0]), $option[1]), $entered)
        );
    }

    public function testMapsValuesToTheMainModuleBuyRequestKey(): void
    {
        $item = $this->createCartItem(
            ['bundle/5/9/1', 'bundle/6/15/1', 'bundle/7/19/1'],
            [['xab-custom/5/9/1', '23'], ['xab-custom/7/19/1', 'Happy Birthday']]
        );

        $this->assertSame(
            ['bundle_custom_option' => [5 => [9 => [1 => '23']], 7 => [19 => [1 => 'Happy Birthday']]]],
            $this->createProvider()->execute($item)
        );
    }

    public function testNumericTextStaysAString(): void
    {
        $result = $this->createProvider()->execute(
            $this->createCartItem(['bundle/5/9/1'], [['xab-custom/5/9/1', '0023']])
        );

        $this->assertSame('0023', $result['bundle_custom_option'][5][9][1]);
    }

    public function testSelectionChosenWithATypedQuantityCounts(): void
    {
        $item = $this->createCartItem([], [['bundle/7/19', '2'], ['xab-custom/7/19/1', 'Hi']]);

        $this->assertSame(
            ['bundle_custom_option' => [7 => [19 => [1 => 'Hi']]]],
            $this->createProvider()->execute($item)
        );
    }

    public function testBundleItemWithoutValuesStillCarriesTheKeySoRequiredOptionsAreEnforced(): void
    {
        $item = $this->createCartItem(['bundle/9/29/1'], [['custom-option/3', 'core custom option']]);

        $this->assertSame(['bundle_custom_option' => []], $this->createProvider()->execute($item));
    }

    public function testBundleItemWithoutValuesAddsNothingWhenRequiredOptionsAreNotEnforced(): void
    {
        $item = $this->createCartItem(['bundle/9/29/1'], []);

        $this->assertSame([], $this->createProvider(true, false)->execute($item));
    }

    public function testValuesAreKeptWhenRequiredOptionsAreNotEnforced(): void
    {
        $item = $this->createCartItem(['bundle/5/9/1'], [['xab-custom/5/9/1', 'A']]);

        $this->assertSame(
            ['bundle_custom_option' => [5 => [9 => [1 => 'A']]]],
            $this->createProvider(true, false)->execute($item)
        );
    }

    public function testItemsThatAreNotBundlesGetNothing(): void
    {
        $item = $this->createCartItem(['configurable/93/52'], [['custom-option/3', 'text']], 'MH01');

        $this->assertSame([], $this->createProvider()->execute($item));
    }

    public function testNullOptionListsAreAccepted(): void
    {
        $this->assertSame([], $this->createProvider()->execute(new CartItem('MH01', 1.0)));
    }

    public function testDisabledExtensionIgnoresEverything(): void
    {
        $item = $this->createCartItem(['bundle/5/9/1'], [['xab-custom/9/29/1', 'forged'], ['xab-custom/5', 'bad']]);

        $this->assertSame([], $this->createProvider(false)->execute($item));
    }

    /**
     * @dataProvider notSelectedProvider
     * @param string[] $selected
     */
    #[DataProvider('notSelectedProvider')]
    public function testRejectsValuesForSelectionsThatAreNotChosen(array $selected, string $uid): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is for a bundle item that is not selected');

        $this->createProvider()->execute($this->createCartItem($selected, [[$uid, 'x']]));
    }

    /**
     * @return array<string, array{string[], string}>
     */
    public static function notSelectedProvider(): array
    {
        return [
            'another product\'s option' => [['bundle/5/9/1'], 'xab-custom/9/29/1'],
            'another selection of the option' => [['bundle/5/9/1'], 'xab-custom/5/10/1'],
            'right selection id under another option' => [['bundle/5/9/1'], 'xab-custom/6/9/1'],
            'no bundle options at all' => [[], 'xab-custom/5/9/1'],
        ];
    }

    public function testRejectsMalformedUidsOfOurs(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Wrong format of the entered option data');

        $this->createProvider()->execute($this->createCartItem(['bundle/5/9/1'], [['xab-custom/5/abc', 'x']]));
    }

    public function testIgnoresMalformedCoreBundleUids(): void
    {
        $item = $this->createCartItem(['bundle/x/9/1', 'bundle/5'], []);

        $this->assertSame([], $this->createProvider()->execute($item));
    }
}
