<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;

class CustomOptionUidTest extends TestCase
{
    /**
     * @var CustomOptionUid
     */
    private $uid;

    protected function setUp(): void
    {
        $this->uid = new CustomOptionUid();
    }

    public function testEncodeFollowsCoreUidShape(): void
    {
        $this->assertSame(base64_encode('xab-custom/5/9/1'), $this->uid->encode(5, 9, 1));
    }

    public function testDecodeReturnsTheEncodedIds(): void
    {
        $this->assertSame(
            ['option_id' => 12, 'selection_id' => 44, 'custom_option_id' => 7],
            $this->uid->decode($this->uid->encode(12, 44, 7))
        );
    }

    /**
     * @dataProvider recognitionProvider
     */
    #[DataProvider('recognitionProvider')]
    public function testIsCustomOptionUid(string $uid, bool $expected): void
    {
        $this->assertSame($expected, $this->uid->isCustomOptionUid($uid));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function recognitionProvider(): array
    {
        return [
            'ours' => [base64_encode('xab-custom/5/9/1'), true],
            'ours, malformed' => [base64_encode('xab-custom/5'), true],
            'core bundle option' => [base64_encode('bundle/5/9/1'), false],
            'core custom option' => [base64_encode('custom-option/1'), false],
            'prefix only as part of another type' => [base64_encode('xab-customer/5/9/1'), false],
            'not base64' => ['%%%', false],
            'empty' => ['', false],
        ];
    }

    /**
     * @dataProvider malformedProvider
     */
    #[DataProvider('malformedProvider')]
    public function testDecodeRejectsMalformedUids(string $decoded): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Wrong format of the entered option data');

        $this->uid->decode(base64_encode($decoded));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'too few parts' => ['xab-custom/5/9'],
            'too many parts' => ['xab-custom/5/9/1/2'],
            'not a number' => ['xab-custom/5/abc/1'],
            'negative' => ['xab-custom/5/-9/1'],
            'zero' => ['xab-custom/0/9/1'],
            'decimal' => ['xab-custom/5/9/1.5'],
            'another type' => ['bundle/5/9/1'],
        ];
    }

    public function testDecodeRejectsInvalidBase64(): void
    {
        $this->expectException(LocalizedException::class);

        $this->uid->decode('%%%');
    }
}
