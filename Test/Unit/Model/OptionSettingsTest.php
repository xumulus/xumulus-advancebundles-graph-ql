<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundles\Model\ResourceModel\BundleAttributes;
use Xumulus\AdvanceBundlesGraphQl\Model\OptionSettings;

class OptionSettingsTest extends TestCase
{
    public function testMapsStoredColumnsLikeTheExtensionAttributes(): void
    {
        $attributes = $this->createStub(BundleAttributes::class);
        $attributes->method('getOptionValues')->willReturn([
            5 => ['option_id' => '5', 'hide' => '1', 'custom_bundle_block' => '', 'gallery_selection' => '0'],
            6 => ['option_id' => '6', 'hide' => null, 'custom_bundle_block' => ' xab-option-note ', 'gallery_selection' => null],
            8 => ['option_id' => '8', 'hide' => '0', 'custom_bundle_block' => null, 'gallery_selection' => '1'],
            11 => ['option_id' => '11', 'hide' => '1', 'custom_bundle_block' => '18', 'gallery_selection' => 1],
        ]);

        $this->assertSame(
            [
                5 => ['visible' => true, 'cms_block' => null, 'gallery_single' => false],
                6 => ['visible' => true, 'cms_block' => 'xab-option-note', 'gallery_single' => false],
                8 => ['visible' => false, 'cms_block' => null, 'gallery_single' => true],
                11 => ['visible' => true, 'cms_block' => '18', 'gallery_single' => true],
            ],
            (new OptionSettings($attributes))->get([5, 6, 8, 11])
        );
    }

    public function testLoadsEachOptionOnceAndLeavesOutUnknownOptions(): void
    {
        $attributes = $this->createMock(BundleAttributes::class);
        $attributes->expects($this->exactly(2))
            ->method('getOptionValues')
            ->willReturnCallback(static function (array $ids): array {
                $rows = [];
                foreach ($ids as $id) {
                    if ($id !== 99) {
                        $rows[$id] = ['hide' => '1', 'custom_bundle_block' => null, 'gallery_selection' => '0'];
                    }
                }

                return $rows;
            });
        $settings = new OptionSettings($attributes);

        $this->assertSame([5, 6], array_keys($settings->get([5, 6, 6, 0])));
        $this->assertSame([6, 7], array_keys($settings->get([6, 7, 99])));
        $this->assertSame([5, 7], array_keys($settings->get([5, 7, 99])));
    }

    public function testResetForgetsLoadedOptions(): void
    {
        $attributes = $this->createMock(BundleAttributes::class);
        $attributes->expects($this->exactly(2))->method('getOptionValues')->willReturn([]);
        $settings = new OptionSettings($attributes);

        $settings->get([5]);
        $settings->_resetState();
        $settings->get([5]);
    }
}
