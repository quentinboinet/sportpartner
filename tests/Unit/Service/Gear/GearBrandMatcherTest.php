<?php

namespace App\Tests\Unit\Service\Gear;

use App\Entity\GearBrand;
use App\Entity\GearType;
use App\Service\Gear\GearBrandMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GearBrandMatcherTest extends TestCase
{
    /** @return list<GearBrand> Extrait de la liste de la migration Version20240024_CreateGear. */
    private static function brands(): array
    {
        $list = [
            ['Adidas', true, false], ['Asics', true, false], ['Brooks', true, false], ['Hoka', true, false],
            ['Inov-8', true, false], ['Kalenji', true, false], ['Kiprun', true, false], ['La Sportiva', true, false],
            ['New Balance', true, false], ['Nike', true, false], ['On', true, false], ['Salomon', true, false],
            ['The North Face', true, false], ['Topo Athletic', true, false], ['Xero Shoes', true, false],
            ['Btwin', false, true], ['Canyon', false, true], ['Cervélo', false, true], ['Rockrider', false, true],
            ['Specialized', false, true], ['Triban', false, true], ['Wilier', false, true], ['YT Industries', false, true],
            ['Decathlon', true, true], ['Scott', true, true], [GearBrand::OTHER, true, true],
        ];

        return array_map(
            static fn (array $b): GearBrand => (new GearBrand())->setName($b[0])->setForShoes($b[1])->setForBike($b[2]),
            $list,
        );
    }

    /** @return iterable<string, array{?string, GearType, string}> */
    public static function brandProvider(): iterable
    {
        yield 'exact'                      => ['Hoka', GearType::Shoes, 'Hoka'];
        yield 'upper case + alias'         => ['HOKA ONE ONE', GearType::Shoes, 'Hoka'];
        yield 'mixed case alias'           => ['Hoka One One', GearType::Shoes, 'Hoka'];
        yield 'hyphen instead of space'    => ['New-Balance', GearType::Shoes, 'New Balance'];
        yield 'short alias'                => ['NB', GearType::Shoes, 'New Balance'];
        yield 'generic word dropped'       => ['On Running', GearType::Shoes, 'On'];
        yield 'brand + product line'       => ['adidas Terrex', GearType::Shoes, 'Adidas'];
        yield 'shoes suffix on both sides' => ['Xero', GearType::Shoes, 'Xero Shoes'];
        yield 'article dropped'            => ['North Face', GearType::Shoes, 'The North Face'];
        yield 'digits glued'               => ['inov8', GearType::Shoes, 'Inov-8'];
        yield 'missing accent'             => ['Cervelo', GearType::Bike, 'Cervélo'];
        yield 'apostrophe'                 => ["B'TWIN", GearType::Bike, 'Btwin'];
        yield 'company suffix'             => ['Canyon Bicycles GmbH', GearType::Bike, 'Canyon'];
        yield 'bike alias'                 => ['S-Works', GearType::Bike, 'Specialized'];
        yield 'industries suffix'          => ['YT', GearType::Bike, 'YT Industries'];
        yield 'both types brand'           => ['SCOTT', GearType::Bike, 'Scott'];
        yield 'Kiprun shoes'               => ['Kiprun', GearType::Shoes, 'Kiprun'];
        yield 'Decathlon Kiprun: specific' => ['Decathlon Kiprun', GearType::Shoes, 'Kiprun'];
        yield 'Kalenji by Decathlon'       => ['Kalenji by Decathlon', GearType::Shoes, 'Kalenji'];
        yield 'Decathlon shoes'            => ['DECATHLON', GearType::Shoes, 'Decathlon'];
        yield 'Decathlon bike'             => ['Decathlon', GearType::Bike, 'Decathlon'];
        yield 'Kiprun on a bike: Decathlon' => ['Kiprun', GearType::Bike, 'Decathlon'];
        yield 'unknown Decathlon brand'    => ['Evadict', GearType::Shoes, 'Decathlon'];
        yield 'Riverside bike: Decathlon'  => ['Riverside', GearType::Bike, 'Decathlon'];
        yield 'shoe brand on a bike'       => ['Hoka', GearType::Bike, GearBrand::OTHER];
        yield 'bike brand on shoes'        => ['Specialized', GearType::Shoes, GearBrand::OTHER];
        yield 'unknown brand'              => ['Le Coq Sportif', GearType::Shoes, GearBrand::OTHER];
        yield 'only generic words'         => ['Running Shoes', GearType::Shoes, GearBrand::OTHER];
        yield 'empty'                      => ['', GearType::Shoes, GearBrand::OTHER];
        yield 'null'                       => [null, GearType::Bike, GearBrand::OTHER];
    }

    #[DataProvider('brandProvider')]
    public function testMatch(?string $stravaBrand, GearType $type, string $expected): void
    {
        $brand = GearBrandMatcher::match($stravaBrand, $type, self::brands());

        $this->assertNotNull($brand);
        $this->assertSame($expected, $brand->getName());
    }

    public function testNoOtherBrandReturnsNull(): void
    {
        $brands = [(new GearBrand())->setName('Hoka')->setForShoes(true)];

        $this->assertNull(GearBrandMatcher::match('Unknown', GearType::Shoes, $brands));
        $this->assertSame('Hoka', GearBrandMatcher::match('hoka', GearType::Shoes, $brands)?->getName());
    }

    public function testKeysAndSimplify(): void
    {
        $this->assertSame('newbalance', GearBrandMatcher::key('New-Balance'));
        $this->assertSame('northface', GearBrandMatcher::key('The North Face'));
        $this->assertSame('cervelo', GearBrandMatcher::key('Cervélo'));
        // simplify() ne retire aucun mot : sert à comparer modèles et surnoms
        $this->assertSame(GearBrandMatcher::simplify('HOKA Speedgoat-6'), GearBrandMatcher::simplify('Hoka speedgoat 6'));
        $this->assertSame('runningshoes', GearBrandMatcher::simplify('Running Shoes'));
    }
}
