<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Cart\BuyRequest;

use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\LegacyBundleDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;

class LegacyBundleDataProviderTest extends TestCase
{
    private const BUNDLE_ITEM = [
        'data' => ['sku' => 'XAB-BUNDLE-FIX', 'quantity' => 1],
        'bundle_options' => [
            ['id' => 9, 'quantity' => 1, 'value' => ['29']],
            ['id' => 10, 'quantity' => 1, 'value' => ['35']],
        ],
    ];

    /**
     * @param bool $enabled
     * @param bool $enforceRequired
     * @return LegacyBundleDataProvider
     */
    private function createProvider(bool $enabled = true, bool $enforceRequired = true): LegacyBundleDataProvider
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $cartProvider = new CustomOptionDataProvider(
            new CustomOptionValues(new CustomOptionUid()),
            $config,
            $enforceRequired
        );

        return new LegacyBundleDataProvider($cartProvider, $config);
    }

    /**
     * Regression: addBundleProductsToCart added a bundle whose chosen item requires an engraving without one,
     * because nothing put bundle_custom_option in its buy request and the main module skipped required texts.
     */
    public function testBundleItemCarriesTheEmptyKeySoRequiredTextsAreEnforced(): void
    {
        $this->assertSame(['bundle_custom_option' => []], $this->createProvider()->execute(self::BUNDLE_ITEM));
    }

    public function testFollowsTheEnforceRequiredSettingOfTheCartProvider(): void
    {
        $this->assertSame([], $this->createProvider(true, false)->execute(self::BUNDLE_ITEM));
    }

    public function testDisabledExtensionAddsNothing(): void
    {
        $this->assertSame([], $this->createProvider(false)->execute(self::BUNDLE_ITEM));
    }

    public function testItemsWithoutBundleOptionsGetNothing(): void
    {
        $provider = $this->createProvider();

        $this->assertSame([], $provider->execute(['data' => ['sku' => 'XAB-A', 'quantity' => 1]]));
        $this->assertSame([], $provider->execute(['data' => ['sku' => 'XAB-A'], 'bundle_options' => []]));
    }
}
