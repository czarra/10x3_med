<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826073658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diary_entries ALTER base_dose_snapshot TYPE INT USING ROUND(base_dose_snapshot)::INTEGER');
        $this->addSql('ALTER TABLE patient_profiles ALTER base_dose TYPE INT USING ROUND(base_dose)::INTEGER');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diary_entries ALTER base_dose_snapshot TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE patient_profiles ALTER base_dose TYPE DOUBLE PRECISION');
    }
}
