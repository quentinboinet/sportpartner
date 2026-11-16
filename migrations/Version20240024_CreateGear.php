<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240024_CreateGear extends AbstractMigration
{
    /**
     * Marques proposées : [nom, chaussures, vélo]. Il n'y a pas de fixtures :
     * pour en ajouter, compléter cette liste ET écrire une nouvelle migration d'INSERT
     * (cette migration est déjà passée en production).
     * "Autre" est toujours placé en dernier.
     *
     * @var list<array{0: string, 1: bool, 2: bool}>
     */
    private const BRANDS = [
        // Chaussures (course, trail, marche, randonnée)
        ['Adidas', true, false],
        ['Altra', true, false],
        ['Asics', true, false],
        ['Brooks', true, false],
        ['Craft', true, false],
        ['Diadora', true, false],
        ['Dynafit', true, false],
        ['Hoka', true, false],
        ['Inov-8', true, false],
        ['Joma', true, false],
        ['Kalenji', true, false],
        ['Karhu', true, false],
        ['Kiprun', true, false],
        ['La Sportiva', true, false],
        ['Merrell', true, false],
        ['Mizuno', true, false],
        ['Mount to Coast', true, false],
        ['New Balance', true, false],
        ['Nike', true, false],
        ['Norda', true, false],
        ['On', true, false],
        ['Puma', true, false],
        ['Quechua', true, false],
        ['Reebok', true, false],
        ['Salomon', true, false],
        ['Saucony', true, false],
        ['Scarpa', true, false],
        ['The North Face', true, false],
        ['Topo Athletic', true, false],
        ['Under Armour', true, false],
        ['VJ', true, false],
        ['Xero Shoes', true, false],
        // Vélos
        ['Argon 18', false, true],
        ['Bianchi', false, true],
        ['BMC', false, true],
        ['Btwin', false, true],
        ['Cannondale', false, true],
        ['Canyon', false, true],
        ['Cervélo', false, true],
        ['Colnago', false, true],
        ['Commencal', false, true],
        ['Cube', false, true],
        ['Factor', false, true],
        ['Felt', false, true],
        ['Focus', false, true],
        ['Ghost', false, true],
        ['Giant', false, true],
        ['Kona', false, true],
        ['Lapierre', false, true],
        ['Liv', false, true],
        ['Look', false, true],
        ['Merida', false, true],
        ['Mondraker', false, true],
        ['Moustache', false, true],
        ['Orbea', false, true],
        ['Pinarello', false, true],
        ['Ridley', false, true],
        ['Rockrider', false, true],
        ['Rose', false, true],
        ['Santa Cruz', false, true],
        ['Specialized', false, true],
        ['Sunn', false, true],
        ['Time', false, true],
        ['Trek', false, true],
        ['Triban', false, true],
        ['Van Rysel', false, true],
        ['Wilier', false, true],
        ['YT Industries', false, true],
        // Les deux
        ['Decathlon', true, true],
        ['Scott', true, true],
    ];

    public function getDescription(): string { return 'Create gearBrand (reference list, seeded) and gear (user shoes / bikes), add activities.gear_id'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gearBrand (
            id       INT AUTO_INCREMENT NOT NULL,
            name     VARCHAR(80) NOT NULL,
            forShoes TINYINT NOT NULL,
            forBike  TINYINT NOT NULL,
            position INT NOT NULL,
            UNIQUE INDEX UNIQ_369A9CC65E237E06 (name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE gear (
            id               INT AUTO_INCREMENT NOT NULL,
            user_id          INT NOT NULL,
            brand_id         INT NOT NULL,
            type             VARCHAR(10) NOT NULL,
            model            VARCHAR(120) NOT NULL,
            nickname         VARCHAR(60) DEFAULT NULL,
            purchasedAt      DATE DEFAULT NULL,
            initialDistanceM INT DEFAULT 0 NOT NULL,
            maxDistanceM     INT DEFAULT NULL,
            isDefault        TINYINT NOT NULL,
            retiredAt        DATE DEFAULT NULL,
            notes            LONGTEXT DEFAULT NULL,
            createdAt        DATETIME NOT NULL,
            INDEX IDX_B44539BA76ED395 (user_id),
            INDEX IDX_B44539B44F5D008 (brand_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_B44539BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_B44539B44F5D008 FOREIGN KEY (brand_id) REFERENCES gearBrand (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE activities ADD gear_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_B5F1AFE577201934 ON activities (gear_id)');
        $this->addSql('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE577201934 FOREIGN KEY (gear_id) REFERENCES gear (id) ON DELETE SET NULL');

        foreach (self::orderedBrands() as $position => [$name, $forShoes, $forBike]) {
            $this->addSql(
                'INSERT INTO gearBrand (name, forShoes, forBike, position) VALUES (?, ?, ?, ?)',
                [$name, (int) $forShoes, (int) $forBike, ($position + 1) * 10]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activities DROP FOREIGN KEY FK_B5F1AFE577201934');
        $this->addSql('DROP INDEX IDX_B5F1AFE577201934 ON activities');
        $this->addSql('ALTER TABLE activities DROP gear_id');
        $this->addSql('DROP TABLE gear');
        $this->addSql('DROP TABLE gearBrand');
    }

    /**
     * Marques par ordre alphabétique, puis "Autre" (chaussures et vélo).
     *
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    private static function orderedBrands(): array
    {
        $brands = self::BRANDS;
        usort($brands, static fn (array $a, array $b): int => strcasecmp($a[0], $b[0]));
        $brands[] = ['Autre', true, true];

        return $brands;
    }
}
