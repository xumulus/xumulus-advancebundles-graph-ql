<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Xumulus\AdvanceBundles\Model\Config;

/**
 * Stubs shared by the batch resolver tests.
 */
trait ResolverTestTrait
{
    /**
     * Field stub with a name.
     *
     * @param string $name
     * @return Field
     */
    private function createField(string $name): Field
    {
        $field = $this->createStub(Field::class);
        $field->method('getName')->willReturn($name);

        return $field;
    }

    /**
     * Batch request item carrying a parent value.
     *
     * @param array<string, mixed>|null $value
     * @return BatchRequestItemInterface
     */
    private function createRequest(?array $value): BatchRequestItemInterface
    {
        $request = $this->createStub(BatchRequestItemInterface::class);
        $request->method('getValue')->willReturn($value);

        return $request;
    }

    /**
     * Store manager whose current store has the id.
     *
     * @param int $storeId
     * @return StoreManagerInterface
     */
    private function createStoreManager(int $storeId): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * Config stub, enabled or not.
     *
     * @param bool $enabled
     * @return Config
     */
    private function createConfig(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        return $config;
    }

    /**
     * Responses in request order.
     *
     * @param BatchResponse $response
     * @param BatchRequestItemInterface[] $requests
     * @return array<mixed>
     */
    private function collect(BatchResponse $response, array $requests): array
    {
        return array_map([$response, 'findResponseFor'], $requests);
    }
}
