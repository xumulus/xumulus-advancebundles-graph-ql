<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\CmsBlockRenderer;
use Xumulus\AdvanceBundlesGraphQl\Model\OptionSettings;
use Xumulus\AdvanceBundlesGraphQl\Model\Resolver\BundleItemSettings;

class BundleItemSettingsTest extends TestCase
{
    use ResolverTestTrait;

    /**
     * @var OptionSettings&Stub a mock in the tests that set expectations
     */
    private $optionSettings;

    /**
     * @var CmsBlockRenderer&Stub a mock in the tests that set expectations
     */
    private $cmsBlockRenderer;

    protected function setUp(): void
    {
        $this->optionSettings = $this->createStub(OptionSettings::class);
        $this->cmsBlockRenderer = $this->createStub(CmsBlockRenderer::class);
    }

    /**
     * Resolve a field for BundleItems with the given option ids.
     *
     * @param string $fieldName
     * @param array<int|null> $optionIds
     * @param bool $enabled
     * @return array<mixed>
     */
    private function resolve(string $fieldName, array $optionIds, bool $enabled = true): array
    {
        $resolver = new BundleItemSettings(
            $this->optionSettings,
            $this->cmsBlockRenderer,
            $this->createConfig($enabled),
            $this->createStoreManager(1)
        );
        $requests = array_map(
            fn (?int $id) => $this->createRequest($id === null ? null : ['option_id' => $id, 'title' => 'x']),
            $optionIds
        );

        return $this->collect(
            $resolver->resolve($this->createStub(ContextInterface::class), $this->createField($fieldName), $requests),
            $requests
        );
    }

    /**
     * @dataProvider fieldProvider
     * @param array<mixed> $expected
     */
    #[DataProvider('fieldProvider')]
    public function testResolvesEveryItemWithOneLoad(string $fieldName, array $expected): void
    {
        $this->optionSettings = $this->createMock(OptionSettings::class);
        $this->optionSettings->expects($this->once())
            ->method('get')
            ->with([5, 6, 8, 0, 404])
            ->willReturn([
                5 => ['visible' => true, 'cms_block' => null, 'gallery_single' => false],
                6 => ['visible' => true, 'cms_block' => 'xab-option-note', 'gallery_single' => false],
                8 => ['visible' => false, 'cms_block' => null, 'gallery_single' => true],
            ]);
        $this->cmsBlockRenderer->method('render')->willReturn(['xab-option-note' => '<p>Note</p>']);

        $this->assertSame($expected, $this->resolve($fieldName, [5, 6, 8, null, 404]));
    }

    /**
     * @return array<string, array{string, array<mixed>}>
     */
    public static function fieldProvider(): array
    {
        return [
            'xab_visible' => ['xab_visible', [true, true, false, null, null]],
            'xab_cms_block' => ['xab_cms_block', [null, 'xab-option-note', null, null, null]],
            'xab_cms_block_html' => ['xab_cms_block_html', [null, '<p>Note</p>', null, null, null]],
            'xab_gallery_single' => ['xab_gallery_single', [false, false, true, null, null]],
        ];
    }

    public function testRendersCmsBlocksOnlyForTheHtmlFieldAndForTheCurrentStore(): void
    {
        $this->optionSettings->method('get')->willReturn([
            6 => ['visible' => true, 'cms_block' => 'xab-option-note', 'gallery_single' => false],
            7 => ['visible' => true, 'cms_block' => null, 'gallery_single' => false],
        ]);
        $this->cmsBlockRenderer = $this->createMock(CmsBlockRenderer::class);
        $this->cmsBlockRenderer->expects($this->once())
            ->method('render')
            ->with(['xab-option-note'], 1)
            ->willReturn(['xab-option-note' => null]);

        $this->assertSame([null, null], $this->resolve('xab_cms_block_html', [6, 7]));
        $this->resolve('xab_cms_block', [6, 7]);
    }

    public function testDisabledExtensionResolvesEverythingToNullWithoutLoading(): void
    {
        $this->optionSettings = $this->createMock(OptionSettings::class);
        $this->optionSettings->expects($this->never())->method('get');
        $this->cmsBlockRenderer = $this->createMock(CmsBlockRenderer::class);
        $this->cmsBlockRenderer->expects($this->never())->method('render');

        $this->assertSame([null, null], $this->resolve('xab_visible', [5, 6], false));
    }
}
