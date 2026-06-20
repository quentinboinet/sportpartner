<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240017_CreateRaces extends AbstractMigration
{
    public function getDescription(): string { return 'Create Race table'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE race (
            id          INT AUTO_INCREMENT NOT NULL,
            user_id     INT NOT NULL,
            name        VARCHAR(200) NOT NULL,
            location    VARCHAR(200) DEFAULT NULL,
            distance_km DOUBLE PRECISION DEFAULT NULL,
            year        SMALLINT NOT NULL,
            intent      VARCHAR(30) NOT NULL DEFAULT 'want_to_do',
            notes       LONGTEXT DEFAULT NULL,
            website     VARCHAR(500) DEFAULT NULL,
            is_done     TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX IDX_RACE_USER (user_id),
            INDEX IDX_RACE_YEAR (year),
            PRIMARY KEY (id),
            CONSTRAINT FK_RACE_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE race'); }
}
