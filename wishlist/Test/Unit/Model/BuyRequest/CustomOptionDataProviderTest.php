<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Test\Unit\Model\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Wishlist\Model\Wishlist\Data\EnteredOption;
use Magento\Wishlist\Model\Wishlist\Data\SelectedOption;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;
use Xumulus\AdvanceBundlesGraphQlWishlist\Model\BuyRequest\CustomOptionDataProvider;

class CustomOptionDataProviderTest extends TestCase
{
    /**
     * @param string[] $selected decoded selected option uids
     * @param array<array{string, string}> $entered [decoded uid, value]
     * @param bool $enabled
     * @return array<string, mixed>
     */
    private function execute(array $selected, array $entered, bool $enabled = true): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $item = new WishlistItem(
            1.0,
            'XAB-BUNDLE-FIX',
            null,
            null,
            null,
            array_map(static fn (string $uid) => new SelectedOption(base64_encode($uid)), $selected),
            array_map(static fn (array $option) => new EnteredOption(base64_encode($option[0]), $option[1]), $entered)
        );

        return (new CustomOptionDataProvider(new CustomOptionValues(new CustomOptionUid()), $config))
            ->execute($item, 2047);
    }

    /**
     * Regression: addProductsToWishlist dropped xab-custom entered options without an error, so the saved item
     * lacked the text and could not be moved to the cart when the text was required.
     */
    public function testTextsAreSavedInTheBuyRequest(): void
    {
        $this->assertSame(
            ['bundle_custom_option' => [9 => [29 => [1 => 'WishText']]]],
            $this->execute(
                ['bundle/9/29/1', 'bundle/10/35/1', 'bundle/11/40/1', 'bundle/12/45/1'],
                [['xab-custom/9/29/1', 'WishText']]
            )
        );
    }

    public function testNothingWithoutTextsAndNothingWhileDisabled(): void
    {
        $this->assertSame([], $this->execute(['bundle/9/29/1'], [['custom-option/3', 'core']]));
        $this->assertSame([], $this->execute(['bundle/9/29/1'], [['xab-custom/9/29/1', 'x']], false));
    }

    /**
     * @dataProvider refusedProvider
     * @param string[] $selected
     * @param array<array{string, string}> $entered
     */
    #[DataProvider('refusedProvider')]
    public function testRefusesWhatAddProductsToCartRefuses(array $selected, array $entered, string $message): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);

        $this->execute($selected, $entered);
    }

    /**
     * @return array<string, array{string[], array<array{string, string}>, string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'selection not chosen' => [['bundle/9/29/1'], [['xab-custom/9/30/1', 'x']], 'not selected'],
            'another option' => [['bundle/9/29/1'], [['xab-custom/5/9/1', 'x']], 'not selected'],
            // Core's wishlist provider keeps only the last selection of an option
            'earlier selection of a multi option' => [
                ['bundle/12/44/1', 'bundle/12/45/1'],
                [['xab-custom/12/44/1', 'x']],
                'not selected',
            ],
            'repeated uid' => [
                ['bundle/9/29/1'],
                [['xab-custom/9/29/1', 'a'], ['xab-custom/9/29/1', 'b']],
                'sent more than once',
            ],
            'malformed uid' => [['bundle/9/29/1'], [['xab-custom/9/x/1', 'a']], 'Wrong format'],
        ];
    }
}
