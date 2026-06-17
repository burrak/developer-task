<?php

declare(strict_types=1);

namespace App\Domain\Barbershop\Repository;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Stylist;
use App\Domain\Barbershop\Exception\NotFoundException;
use App\Domain\ValueObject\Uuid;
use DateTimeImmutable;

interface BookingRepositoryInterface
{
    /** @throws NotFoundException */
    public function getById(Uuid $id): Booking;

    public function hasActiveOverlappingBooking(
        Stylist $stylist,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
    ): bool;

    public function lockStylistBookingWrites(Stylist $stylist): void;

    public function findIdempotentBookingId(
        Stylist $stylist,
        string $idempotencyKey,
        string $requestHash,
    ): ?string;

    public function saveIdempotencyKey(
        Stylist $stylist,
        string $idempotencyKey,
        string $correlationId,
        string $requestHash,
        Booking $booking,
    ): void;

    public function save(Booking $booking): void;
}
