<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQlWishlist\Test\Unit\Plugin;

use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Wishlist\Model\Item;
use Magento\WishlistGraphQl\Model\CartItems\CartItemsRequestBuilder;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQlWishlist\Plugin\NormalizeLegacyBuyRequest;

class NormalizeLegacyBuyRequestTest extends TestCase
{
    /**
     * Run the plugin on a wishlist item whose info_buyRequest option holds the value, and return the new value.
     *
     * @param string|null $value
     * @return mixed
     */
    private function normalize(?string $value)
    {
        $option = $value === null ? null : new DataObject(['value' => $value]);
        $item = $this->createStub(Item::class);
        $item->method('getOptionByCode')->willReturnMap([['info_buyRequest', $option]]);

        $plugin = new NormalizeLegacyBuyRequest(new Json());
        $this->assertNull($plugin->beforeBuild($this->createStub(CartItemsRequestBuilder::class), $item));

        return $option ? $option->getData('value') : null;
    }

    public function testLegacyTextsMoveOutOfBundleOption(): void
    {
        $result = $this->normalize(json_encode([
            'bundle_option' => ['9' => '29', 'custom_option' => ['9' => ['29' => ['1' => 'Old']]]],
            'bundle_option_qty' => ['9' => '1'],
        ]));

        $this->assertSame(
            [
                'bundle_option' => ['9' => '29'],
                'bundle_option_qty' => ['9' => '1'],
                'bundle_custom_option' => ['9' => ['29' => ['1' => 'Old']]],
            ],
            json_decode((string)$result, true)
        );
    }

    public function testCurrentLocationWins(): void
    {
        $result = $this->normalize(json_encode([
            'bundle_option' => ['9' => '29', 'custom_option' => ['9' => ['29' => ['1' => 'Old']]]],
            'bundle_custom_option' => ['9' => ['29' => ['1' => 'New']]],
        ]));

        $this->assertSame(
            ['bundle_option' => ['9' => '29'], 'bundle_custom_option' => ['9' => ['29' => ['1' => 'New']]]],
            json_decode((string)$result, true)
        );
    }

    public function testOtherBuyRequestsAreLeftAlone(): void
    {
        $current = '{"bundle_option":{"9":"29"},"bundle_custom_option":{"9":{"29":{"1":"custom_option"}}}}';

        $this->assertSame($current, $this->normalize($current));
        $this->assertSame('not json custom_option', $this->normalize('not json custom_option'));
        $this->assertNull($this->normalize(null));
    }
}
