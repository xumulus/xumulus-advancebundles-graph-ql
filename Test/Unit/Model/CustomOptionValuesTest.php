<?php
declare(strict_types=1);

namespace Xumulus\AdvanceBundlesGraphQl\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionUid;
use Xumulus\AdvanceBundlesGraphQl\Model\CustomOptionValues;

class CustomOptionValuesTest extends TestCase
{
    /**
     * @param string[] $decoded
     * @return string[]
     */
    private static function encode(array $decoded): array
    {
        return array_map('base64_encode', $decoded);
    }

    public function testChosenSelectionsFromSelectedAndEnteredBundleUids(): void
    {
        $uids = self::encode(['bundle/5/9/1', 'bundle/7/19/1', 'bundle/7/20/2', 'bundle/8/24', 'custom-option/1']);

        $this->assertSame(
            [5 => [9 => true], 7 => [19 => true, 20 => true], 8 => [24 => true]],
            (new CustomOptionValues(new CustomOptionUid()))->getChosenSelections($uids)
        );
    }

    public function testLastPerOptionKeepsOnlyTheLastSelectionOfEachOption(): void
    {
        $uids = self::encode(['bundle/7/19/1', 'bundle/7/20/1', 'bundle/5/9/1']);

        $this->assertSame(
            [7 => [20 => true], 5 => [9 => true]],
            (new CustomOptionValues(new CustomOptionUid()))->getChosenSelections($uids, true)
        );
    }

    /**
     * @dataProvider malformedCoreUidProvider
     */
    #[DataProvider('malformedCoreUidProvider')]
    public function testMalformedCoreUidsAreSkipped(string $uid): void
    {
        $this->assertSame([], (new CustomOptionValues(new CustomOptionUid()))->getChosenSelections([$uid]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCoreUidProvider(): array
    {
        return [
            'too short' => [base64_encode('bundle/5')],
            'not numeric' => [base64_encode('bundle/x/9/1')],
            'not base64' => ['%%%'],
            'another type' => [base64_encode('configurable/93/52')],
        ];
    }

    public function testCollectKeysValuesByOptionSelectionAndCustomOption(): void
    {
        $values = new CustomOptionValues(new CustomOptionUid());

        $this->assertSame(
            [5 => [9 => [1 => '23']], 7 => [19 => [1 => 'Happy Birthday', 2 => '']]],
            $values->collect(
                [
                    [base64_encode('xab-custom/5/9/1'), '23'],
                    [base64_encode('custom-option/3'), 'core option, skipped'],
                    [base64_encode('xab-custom/7/19/1'), 'Happy Birthday'],
                    [base64_encode('xab-custom/7/19/2'), ''],
                ],
                [5 => [9 => true], 7 => [19 => true]]
            )
        );
    }

    public function testCollectRejectsASelectionThatIsNotChosen(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is for a bundle item that is not selected');

        (new CustomOptionValues(new CustomOptionUid()))
            ->collect([[base64_encode('xab-custom/5/10/1'), 'x']], [5 => [9 => true]]);
    }

    public function testCollectRejectsARepeatedUid(): void
    {
        $uid = base64_encode('xab-custom/5/9/1');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(sprintf('The entered option "%s" is sent more than once.', $uid));

        (new CustomOptionValues(new CustomOptionUid()))
            ->collect([[$uid, 'First'], [$uid, 'Second']], [5 => [9 => true]]);
    }

    public function testCollectRejectsAMalformedUidOfOurs(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Wrong format of the entered option data');

        (new CustomOptionValues(new CustomOptionUid()))
            ->collect([[base64_encode('xab-custom/5/9'), 'x']], [5 => [9 => true]]);
    }
}
