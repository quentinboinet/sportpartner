<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240008_RenameAthleteProfileTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename athlete_profiles → AthleteProfile, user_id → user (default naming strategy)';
    }

    public function up(Schema $schema): void
    {
        // Drop old FK before renaming
        $this->addSql('ALTER TABLE athlete_profiles DROP FOREIGN KEY FK_AP_USER');

        // Rename table to match class name (doctrine.orm.naming_strategy.default)
        $this->addSql('RENAME TABLE athlete_profiles TO AthleteProfile');

        // Rename FK column: user_id → user
        $this->addSql('ALTER TABLE AthleteProfile RENAME COLUMN user_id TO `user`');

        // Replace unique index with Doctrine-generated name
        $this->addSql('ALTER TABLE AthleteProfile DROP INDEX UNIQ_56423B95A76ED395');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2DCD8848D93D649 ON AthleteProfile (`user`)');

        // Recreate FK with Doctrine-generated name
        $this->addSql('ALTER TABLE AthleteProfile ADD CONSTRAINT FK_D2DCD8848D93D649 FOREIGN KEY (`user`) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE AthleteProfile DROP FOREIGN KEY FK_D2DCD8848D93D649');
        $this->addSql('ALTER TABLE AthleteProfile DROP INDEX UNIQ_D2DCD8848D93D649');

        $this->addSql('ALTER TABLE AthleteProfile RENAME COLUMN `user` TO user_id');
        $this->addSql('RENAME TABLE AthleteProfile TO athlete_profiles');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_56423B95A76ED395 ON athlete_profiles (user_id)');
        $this->addSql('ALTER TABLE athlete_profiles ADD CONSTRAINT FK_AP_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }
}
