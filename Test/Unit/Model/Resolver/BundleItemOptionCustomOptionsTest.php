<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\Resolver\BundleItemOptionCustomOptions;
use Xumulus\AdvanceBundlesGraphQl\Model\SelectionCustomOptions;

class BundleItemOptionCustomOptionsTest extends TestCase
{
    use ResolverTestTrait;

    /**
     * @var SelectionCustomOptions&Stub a mock in the tests that set expectations
     */
    private $selectionCustomOptions;

    protected function setUp(): void
    {
        $this->selectionCustomOptions = $this->createStub(SelectionCustomOptions::class);
    }

    /**
     * Resolve a field for BundleItemOptions with the given values.
     *
     * @param string $fieldName
     * @param array<array<string, mixed>|null> $values
     * @param bool $enabled
     * @return array<mixed>
     */
    private function resolve(string $fieldName, array $values, bool $enabled = true): array
    {
        $resolver = new BundleItemOptionCustomOptions(
            $this->selectionCustomOptions,
            $this->createConfig($enabled),
            $this->createStoreManager(2)
        );
        $requests = array_map([$this, 'createRequest'], $values);

        return $this->collect(
            $resolver->resolve($this->createStub(ContextInterface::class), $this->createField($fieldName), $requests),
            $requests
        );
    }

    /**
     * BundleItemOption values as core's link collection builds them.
     *
     * @return array<array<string, mixed>|null>
     */
    private function getValues(): array
    {
        return [
            ['selection_id' => '9', 'option_id' => '5', 'product_id' => '2041', 'entity_id' => '2041'],
            ['selection_id' => '10', 'option_id' => '5', 'entity_id' => '2042'],
            ['selection_id' => '11', 'option_id' => '5'],
            null,
        ];
    }

    public function testLoadsEverySelectionOfTheRequestTogether(): void
    {
        $option = ['uid' => 'eGFiLWN1c3RvbS81LzkvMQ==', 'option_id' => 1, 'required' => true];
        $this->selectionCustomOptions = $this->createMock(SelectionCustomOptions::class);
        $this->selectionCustomOptions->expects($this->once())
            ->method('get')
            ->with(
                [
                    9 => ['selection_id' => 9, 'option_id' => 5, 'product_id' => 2041],
                    10 => ['selection_id' => 10, 'option_id' => 5, 'product_id' => 2042],
                ],
                2
            )
            ->willReturn([
                9 => ['required' => true, 'options' => [$option]],
                10 => ['required' => false, 'options' => []],
            ]);

        $this->assertSame([[$option], [], null, null], $this->resolve('xab_custom_options', $this->getValues()));
    }

    public function testRequiredFlag(): void
    {
        $this->selectionCustomOptions->method('get')->willReturn([
            9 => ['required' => true, 'options' => []],
            10 => ['required' => false, 'options' => []],
        ]);

        $this->assertSame(
            [true, false, null, null],
            $this->resolve('xab_custom_option_required', $this->getValues())
        );
    }

    public function testDisabledExtensionResolvesEverythingToNullWithoutLoading(): void
    {
        $this->selectionCustomOptions = $this->createMock(SelectionCustomOptions::class);
        $this->selectionCustomOptions->expects($this->never())->method('get');

        $this->assertSame(
            [null, null, null, null],
            $this->resolve('xab_custom_options', $this->getValues(), false)
        );
    }
}
