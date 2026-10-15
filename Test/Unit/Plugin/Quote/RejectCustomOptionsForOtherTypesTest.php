<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Plugin\Quote\RejectCustomOptionsForOtherTypes;

class RejectCustomOptionsForOtherTypesTest extends TestCase
{
    /**
     * @param string $typeId
     * @param DataObject|int $request
     * @return null
     */
    private function callPlugin(string $typeId, $request)
    {
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($typeId);

        return (new RejectCustomOptionsForOtherTypes())
            ->beforeAddProduct($this->createStub(Quote::class), $product, $request);
    }

    /**
     * Regression: values sent with core bundle uids for a simple product were stored in its info_buyRequest,
     * together with the internal entered_options mark, and the text disappeared without an error.
     */
    public function testValuesForAProductThatIsNotABundleAreRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('can only be entered for a bundle product');

        $this->callPlugin('simple', new DataObject([
            'qty' => 1,
            'bundle_option' => [5 => '9'],
            'bundle_custom_option' => [5 => [9 => [1 => 'T']]],
            CustomOptionDataProvider::VALUES_FLAG => true,
        ]));
    }

    public function testTheEmptyEnforcingKeyIsRemovedForOtherTypes(): void
    {
        $request = new DataObject(['qty' => 1, 'bundle_option' => [5 => '9'], 'bundle_custom_option' => []]);

        $this->assertNull($this->callPlugin('configurable', $request));
        $this->assertSame(['qty' => 1, 'bundle_option' => [5 => '9']], $request->getData());
    }

    public function testBundlesAreLeftToTheBundleType(): void
    {
        $data = [
            'qty' => 1,
            'bundle_custom_option' => [5 => [9 => [1 => 'T']]],
            CustomOptionDataProvider::VALUES_FLAG => true,
        ];
        $request = new DataObject($data);

        $this->assertNull($this->callPlugin('bundle', $request));
        $this->assertSame($data, $request->getData());
    }

    public function testOtherRequestsAreUntouched(): void
    {
        $data = ['qty' => 2, 'options' => [3 => 'core custom option'], 'bundle_custom_option' => [5 => 'kept']];
        $request = new DataObject($data);

        $this->assertNull($this->callPlugin('simple', $request));
        $this->assertSame($data, $request->getData());
        $this->assertNull($this->callPlugin('simple', 3));
    }
}
