<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826092028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_base_dose_adjustment_histories_user_accepted_at ON base_dose_adjustment_histories (user_id, accepted_at)');
        $this->addSql('CREATE INDEX idx_ratio_adjustment_histories_user_accepted_at ON ratio_adjustment_histories (user_id, accepted_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_base_dose_adjustment_histories_user_accepted_at');
        $this->addSql('DROP INDEX idx_ratio_adjustment_histories_user_accepted_at');
    }
}
