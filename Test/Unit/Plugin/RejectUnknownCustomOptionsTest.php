<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Plugin;

use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\Config;
use Xumulus\AdvanceBundlesGraphQl\Model\Cart\BuyRequest\CustomOptionDataProvider;
use Xumulus\AdvanceBundlesGraphQl\Plugin\RejectUnknownCustomOptions;

class RejectUnknownCustomOptionsTest extends TestCase
{
    /**
     * @var Type
     */
    private $type;

    /**
     * @var Product
     */
    private $bundle;

    /**
     * @var Product chosen selection 9 of option 5, with text custom option 1 and a drop-down 2
     */
    private $alpha;

    /**
     * @var Product chosen selection 15 of option 6, with no custom options
     */
    private $bravo;

    protected function setUp(): void
    {
        $this->type = $this->createStub(Type::class);
        $this->bundle = $this->buildProduct(['entity_id' => 2046, 'store_id' => 1]);
        $this->alpha = $this->buildProduct([
            'entity_id' => 2041,
            'name' => 'AB Child Alpha',
            'option_id' => 5,
            'selection_id' => 9,
            'options' => [
                new DataObject(['option_id' => 1, 'type' => 'field']),
                new DataObject(['option_id' => 2, 'type' => 'drop_down']),
            ],
        ]);
        $this->bravo = $this->buildProduct([
            'entity_id' => 2042,
            'name' => 'AB Child Bravo',
            'option_id' => 6,
            'selection_id' => 15,
        ]);
    }

    /**
     * Product with the given data and no collaborators.
     *
     * @param array<string, mixed> $data
     * @return Product
     */
    private function buildProduct(array $data): Product
    {
        /** @var Product $product */
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setData($data);

        return $product;
    }

    /**
     * Plugin with the extension enabled or not.
     *
     * @param bool $enabled
     * @param ProductCustomOptionRepositoryInterface|null $repository
     * @return RejectUnknownCustomOptions
     */
    private function createPlugin(bool $enabled = true, $repository = null): RejectUnknownCustomOptions
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return new RejectUnknownCustomOptions(
            $config,
            $repository ?? $this->createStub(ProductCustomOptionRepositoryInterface::class)
        );
    }

    /**
     * Run the plugin around core's result for the bundle and the two chosen selections.
     *
     * @param array<mixed> $buyRequest
     * @param bool $enabled
     * @param ProductCustomOptionRepositoryInterface|null $repository
     * @param bool $fromEnteredOptions whether the buy request carries CustomOptionDataProvider's mark
     * @return array<mixed>|string
     */
    private function invoke(
        array $buyRequest,
        bool $enabled = true,
        $repository = null,
        bool $fromEnteredOptions = true
    ) {
        $plugin = $this->createPlugin($enabled, $repository);
        if ($fromEnteredOptions) {
            $buyRequest[CustomOptionDataProvider::VALUES_FLAG] = true;
        }
        $request = new DataObject($buyRequest);
        $plugin->beforePrepareForCartAdvanced($this->type, $request, $this->bundle);

        return $plugin->afterPrepareForCartAdvanced(
            $this->type,
            [$this->bundle, $this->alpha, $this->bravo],
            $request,
            $this->bundle
        );
    }

    public function testValuesForTextOptionsOfChosenSelectionsPass(): void
    {
        $result = $this->invoke(['bundle_custom_option' => [5 => [9 => [1 => '23']]]]);

        $this->assertSame([$this->bundle, $this->alpha, $this->bravo], $result);
    }

    public function testNoValuesPass(): void
    {
        $this->assertIsArray($this->invoke(['bundle_custom_option' => []]));
        $this->assertIsArray($this->invoke([]));
    }

    public function testValueForASelectionThatIsNotChosenIsRejected(): void
    {
        $this->assertSame(
            'A custom option was entered for a bundle item that is not selected.',
            $this->invoke(['bundle_custom_option' => [9 => [29 => [1 => 'x']]]])
        );
    }

    public function testSelectionIdUnderTheWrongOptionIsRejected(): void
    {
        $this->assertIsString($this->invoke(['bundle_custom_option' => [6 => [9 => [1 => 'x']]]]));
    }

    public function testMalformedStructureIsRejected(): void
    {
        $this->assertIsString($this->invoke(['bundle_custom_option' => [5 => 'x']]));
        $this->assertIsString($this->invoke(['bundle_custom_option' => [5 => [9 => 'x']]]));
    }

    public function testUnknownCustomOptionIsRejected(): void
    {
        $this->assertSame(
            'The custom option with ID "999" is not available for "AB Child Alpha".',
            $this->invoke(['bundle_custom_option' => [5 => [9 => [999 => 'x']]]])
        );
    }

    public function testCustomOptionThatIsNotATextOptionIsRejected(): void
    {
        $this->assertIsString($this->invoke(['bundle_custom_option' => [5 => [9 => [2 => 'x']]]]));
    }

    public function testSelectionWithoutCustomOptionsRejectsAnyValue(): void
    {
        $this->assertSame(
            'The custom option with ID "1" is not available for "AB Child Bravo".',
            $this->invoke(['bundle_custom_option' => [6 => [15 => [1 => '']]]])
        );
    }

    public function testCustomOptionsAreLoadedWhenTheSelectionHasOptionsButNoneAreAttached(): void
    {
        $this->bravo->setData('has_options', 1);
        $repository = $this->createMock(ProductCustomOptionRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getProductOptions')
            ->with($this->bravo)
            ->willReturn([new DataObject(['option_id' => 4, 'type' => 'area'])]);

        $this->assertIsArray($this->invoke(['bundle_custom_option' => [6 => [15 => [4 => 'x']]]], true, $repository));
    }

    public function testLegacyLocationIsChecked(): void
    {
        $this->assertIsString($this->invoke(['bundle_option' => ['custom_option' => [9 => [29 => [1 => 'x']]]]]));
    }

    public function testDisabledExtensionChecksNothing(): void
    {
        $this->assertIsArray($this->invoke(['bundle_custom_option' => [9 => [29 => [1 => 'x']]]], false));
    }

    public function testCoreErrorPassesThrough(): void
    {
        $plugin = $this->createPlugin();
        $request = new DataObject([
            'bundle_custom_option' => [9 => [29 => [1 => 'x']]],
            CustomOptionDataProvider::VALUES_FLAG => true,
        ]);
        $plugin->beforePrepareForCartAdvanced($this->type, $request, $this->bundle);

        $this->assertSame(
            'Please specify product option(s).',
            $plugin->afterPrepareForCartAdvanced(
                $this->type,
                'Please specify product option(s).',
                $request,
                $this->bundle
            )
        );
    }

    /**
     * Regression: reorderItems (and any other re-prepare in the graphql area) passes the stored info_buyRequest.
     * A 1.3.x storefront order carries an input for every selection, chosen or not, mostly empty.
     */
    public function testBuyRequestNotFromEnteredOptionsIsLeftToTheMainModule(): void
    {
        $legacy = [
            'bundle_option' => [5 => '9', 6 => '15'],
            'bundle_custom_option' => [5 => [10 => [1 => '']], 6 => [14 => [1 => '']], 9 => [29 => [999 => 'x']]],
        ];

        $this->assertSame(
            [$this->bundle, $this->alpha, $this->bravo],
            $this->invoke($legacy, true, null, false)
        );
    }

    public function testLegacyLocationNotFromEnteredOptionsIsLeftToTheMainModule(): void
    {
        $legacy = ['bundle_option' => [5 => '9', 'custom_option' => [6 => [14 => [1 => '']]]]];

        $this->assertIsArray($this->invoke($legacy, true, null, false));
    }

    public function testTheMarkIsRemovedBeforeCoreStoresTheBuyRequest(): void
    {
        $request = new DataObject([
            'bundle_custom_option' => [5 => [9 => [1 => '23']]],
            CustomOptionDataProvider::VALUES_FLAG => true,
        ]);

        $this->createPlugin()->beforePrepareForCartAdvanced($this->type, $request, $this->bundle);

        $this->assertFalse($request->hasData(CustomOptionDataProvider::VALUES_FLAG));
        $this->assertSame([5 => [9 => [1 => '23']]], $request->getData('bundle_custom_option'));
    }

    public function testTheMarkAppliesToOneCallOnly(): void
    {
        $plugin = $this->createPlugin();
        $request = new DataObject([
            'bundle_custom_option' => [9 => [29 => [1 => 'x']]],
            CustomOptionDataProvider::VALUES_FLAG => true,
        ]);
        $plugin->beforePrepareForCartAdvanced($this->type, $request, $this->bundle);
        $result = [$this->bundle, $this->alpha, $this->bravo];

        $this->assertIsString($plugin->afterPrepareForCartAdvanced($this->type, $result, $request, $this->bundle));
        $plugin->beforePrepareForCartAdvanced($this->type, $request, $this->bundle);
        $this->assertSame($result, $plugin->afterPrepareForCartAdvanced($this->type, $result, $request, $this->bundle));
    }
}
