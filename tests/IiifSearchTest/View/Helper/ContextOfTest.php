<?php declare(strict_types=1);

namespace IiifSearchTest\View\Helper;

use IiifSearchTest\IiifSearchTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests the context of the hits, counted in words even when a zone of the ocr
 * is a whole line.
 */
class ContextOfTest extends TestCase
{
    use IiifSearchTestTrait;

    protected function contextOf(array $zones, int $index, string $match): array
    {
        $helper = $this->createHelperStub();
        $method = new ReflectionMethod(get_class($helper), 'contextOf');
        $method->setAccessible(true);
        return $method->invoke($helper, $zones, $index, $match);
    }

    public function testZonesOfWords(): void
    {
        $zones = ['one', 'two', 'three', 'four', 'five', 'six', 'fox', 'seven', 'eight'];
        $this->assertSame([
            'match' => 'fox',
            'before' => 'two three four five six ',
            'after' => ' seven eight',
        ], $this->contextOf($zones, 6, 'fox'));
    }

    public function testZonesOfLinesAreCountedInWords(): void
    {
        // Like the alto of eScriptorium: one string by line.
        $zones = [
            'Sectio V. Introductio in theologiam.',
            'Cap. I Encyclopaedia et Methodologia.',
            'R. Systema scientiarum theologicarum.',
        ];
        $this->assertSame([
            'match' => 'theologiam',
            'before' => 'Sectio V. Introductio in ',
            'after' => '. Cap. I Encyclopaedia et Methodologia.',
        ], $this->contextOf($zones, 0, 'theologiam'));
        $this->assertSame([
            'match' => 'Systema',
            'before' => 'I Encyclopaedia et Methodologia. R. ',
            'after' => ' scientiarum theologicarum.',
        ], $this->contextOf($zones, 2, 'Systema'));
    }

    public function testPartOfWordIsGluedToTheMatch(): void
    {
        $this->assertSame([
            'match' => 'theolog',
            'before' => 'Introductio in ',
            'after' => 'iam. Cap.',
        ], $this->contextOf(['Introductio in theologiam.', 'Cap.'], 0, 'theolog'));
    }

    public function testUnknownMatchUsesTheWholeZone(): void
    {
        $this->assertSame([
            'match' => 'house',
            'before' => 'the ',
            'after' => null,
        ], $this->contextOf(['the', 'house'], 1, 'maison'));
    }
}
