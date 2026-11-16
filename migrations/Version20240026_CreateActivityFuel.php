<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240026_CreateActivityFuel extends AbstractMigration
{
    public function getDescription(): string { return 'Create activityFuel (food and drinks consumed during an activity)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE activityFuel (
            id           INT AUTO_INCREMENT NOT NULL,
            activity_id  INT NOT NULL,
            kind         VARCHAR(10) NOT NULL,
            itemKey      VARCHAR(40) NOT NULL,
            label        VARCHAR(60) DEFAULT NULL,
            quantity     INT NOT NULL,
            carbsPerUnit DOUBLE PRECISION NOT NULL,
            INDEX IDX_AEDD47AF81C06096 (activity_id),
            UNIQUE INDEX UNIQ_ACTIVITY_FUEL_ITEM (activity_id, itemKey),
            PRIMARY KEY (id),
            CONSTRAINT FK_AEDD47AF81C06096 FOREIGN KEY (activity_id) REFERENCES activities (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activityFuel');
    }
}
