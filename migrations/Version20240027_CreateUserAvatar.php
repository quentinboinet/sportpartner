<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240027_CreateUserAvatar extends AbstractMigration
{
    public function getDescription(): string { return 'Create userAvatar (profile photo stored in the database, one per user) and add users.avatarUpdatedAt'; }

    public function up(Schema $schema): void
    {
        // Image déjà retraitée (WebP 256 x 256 au plus, quelques dizaines de Ko) : stockée en base
        // plutôt que sur disque, elle survit aux déploiements.
        $this->addSql('CREATE TABLE userAvatar (
            id        INT AUTO_INCREMENT NOT NULL,
            user_id   INT NOT NULL,
            mimeType  VARCHAR(32) NOT NULL,
            data      LONGBLOB NOT NULL,
            width     INT NOT NULL,
            height    INT NOT NULL,
            updatedAt DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_792160DDA76ED395 (user_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_792160DDA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // NULL = pas de photo ; sinon version de l'URL de l'image (aucune requête sur userAvatar pour l'afficher)
        $this->addSql('ALTER TABLE users ADD avatarUpdatedAt DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP avatarUpdatedAt');
        $this->addSql('DROP TABLE userAvatar');
    }
}
