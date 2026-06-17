<?php

declare(strict_types=1);

namespace App\Infrastructure\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-stylist booking write lock table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE barbershop_booking_locks (stylist_id CHAR(36) NOT NULL --(DC2Type:uuid)
        , version INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(stylist_id), CONSTRAINT FK_BOOKING_LOCK_STYLIST FOREIGN KEY (stylist_id) REFERENCES barbershop_stylists (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO barbershop_booking_locks (stylist_id, version) SELECT id, 0 FROM barbershop_stylists');
        $this->addSql('CREATE TABLE barbershop_booking_idempotency_keys (stylist_id CHAR(36) NOT NULL --(DC2Type:uuid)
        , idempotency_key VARCHAR(128) NOT NULL, booking_id CHAR(36) NOT NULL --(DC2Type:uuid)
        , correlation_id VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , PRIMARY KEY(stylist_id, idempotency_key), CONSTRAINT FK_BOOKING_IDEMPOTENCY_STYLIST FOREIGN KEY (stylist_id) REFERENCES barbershop_stylists (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_BOOKING_IDEMPOTENCY_BOOKING FOREIGN KEY (booking_id) REFERENCES barbershop_bookings (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_BOOKING_IDEMPOTENCY_BOOKING ON barbershop_booking_idempotency_keys (booking_id)');
        $this->addSql('CREATE INDEX IDX_BOOKING_IDEMPOTENCY_CORRELATION ON barbershop_booking_idempotency_keys (correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE barbershop_booking_idempotency_keys');
        $this->addSql('DROP TABLE barbershop_booking_locks');
    }
}
