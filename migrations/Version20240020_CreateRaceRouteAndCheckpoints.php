<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240020_CreateRaceRouteAndCheckpoints extends AbstractMigration
{
    public function getDescription(): string { return 'Create raceRoute (imported GPX course) and raceCheckpoint (race plan checkpoints)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE raceRoute (
            id        INT AUTO_INCREMENT NOT NULL,
            race_id   INT NOT NULL,
            fileName  VARCHAR(255) NOT NULL,
            points    JSON NOT NULL,
            distanceM INT NOT NULL,
            gainM     INT NOT NULL,
            lossM     INT NOT NULL,
            minEle    DOUBLE PRECISION DEFAULT NULL,
            maxEle    DOUBLE PRECISION DEFAULT NULL,
            createdAt DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_C35D6F296E59D40D (race_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_C35D6F296E59D40D FOREIGN KEY (race_id) REFERENCES race (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE raceCheckpoint (
            id            INT AUTO_INCREMENT NOT NULL,
            race_id       INT NOT NULL,
            name          VARCHAR(120) NOT NULL,
            distanceM     INT NOT NULL,
            type          VARCHAR(30) NOT NULL,
            stopSeconds   INT DEFAULT NULL,
            cutoffSeconds INT DEFAULT NULL,
            notes         LONGTEXT DEFAULT NULL,
            createdAt     DATETIME NOT NULL,
            INDEX IDX_D447156E6E59D40D (race_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_D447156E6E59D40D FOREIGN KEY (race_id) REFERENCES race (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE raceCheckpoint');
        $this->addSql('DROP TABLE raceRoute');
    }
}
