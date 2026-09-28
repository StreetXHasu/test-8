<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, shared image blobs and per-user images';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        $this->addSql('CREATE TABLE image_blobs (content_hash VARCHAR(64) NOT NULL, storage_key VARCHAR(80) NOT NULL, mime_type VARCHAR(32) NOT NULL, size_bytes INT NOT NULL, width INT NOT NULL, height INT NOT NULL, reference_count INT NOT NULL, PRIMARY KEY(content_hash))');

        $this->addSql('CREATE TABLE images (id UUID NOT NULL, user_id UUID NOT NULL, blob_hash VARCHAR(64) NOT NULL, original_filename VARCHAR(180) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_images_user_created ON images (user_id, created_at, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_images_user_blob ON images (user_id, blob_hash)');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT fk_images_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE images ADD CONSTRAINT fk_images_blob FOREIGN KEY (blob_hash) REFERENCES image_blobs (content_hash) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE images DROP CONSTRAINT fk_images_blob');
        $this->addSql('ALTER TABLE images DROP CONSTRAINT fk_images_user');
        $this->addSql('DROP TABLE images');
        $this->addSql('DROP TABLE image_blobs');
        $this->addSql('DROP TABLE users');
    }
}
