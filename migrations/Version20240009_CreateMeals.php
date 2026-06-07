<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240009_CreateMeals extends AbstractMigration
{
    public function getDescription(): string { return 'Create Meal table'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE Meal (
            id          INT AUTO_INCREMENT NOT NULL,
            user_id     INT NOT NULL,
            mealDate    DATE NOT NULL,
            mealTime    VARCHAR(5)   DEFAULT NULL,
            mealType    VARCHAR(30)  NOT NULL DEFAULT 'lunch',
            name        VARCHAR(200) NOT NULL,
            description VARCHAR(500) DEFAULT NULL,
            carbsG      DOUBLE PRECISION DEFAULT NULL,
            proteinsG   DOUBLE PRECISION DEFAULT NULL,
            fatsG       DOUBLE PRECISION DEFAULT NULL,
            kcal        INT DEFAULT NULL,
            INDEX IDX_MEAL_USER (user_id),
            INDEX IDX_MEAL_DATE (mealDate),
            PRIMARY KEY (id),
            CONSTRAINT FK_MEAL_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE Meal'); }
}
