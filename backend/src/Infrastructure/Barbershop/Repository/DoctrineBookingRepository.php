<?php

declare(strict_types=1);

namespace App\Infrastructure\Barbershop\Repository;

use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Stylist;
use App\Domain\Barbershop\Enum\BookingStatus;
use App\Domain\Barbershop\Exception\NotFoundException;
use App\Domain\Barbershop\Repository\BookingRepositoryInterface;
use App\Domain\ValueObject\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use RuntimeException;

final class DoctrineBookingRepository implements BookingRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function getById(Uuid $id): Booking
    {
        return $this->em->find(Booking::class, $id)
            ?? throw new NotFoundException("Booking {$id} not found");
    }

    public function hasActiveOverlappingBooking(
        Stylist $stylist,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
    ): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(Booking::class, 'b')
            ->where('b.stylist = :stylist')
            ->andWhere('b.startTime < :endTime')
            ->andWhere('b.endTime > :startTime')
            ->andWhere('b.status != :rejected')
            ->setParameter('stylist', $stylist)
            ->setParameter('startTime', $startTime)
            ->setParameter('endTime', $endTime)
            ->setParameter('rejected', BookingStatus::Rejected->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function lockStylistBookingWrites(Stylist $stylist): void
    {
        $stylistId = $stylist->getId()->toString();
        $affectedRows = $this->em->getConnection()->executeStatement(
            'UPDATE barbershop_booking_locks SET version = version + 1 WHERE stylist_id = ?',
            [$stylistId],
        );

        if ($affectedRows !== 1) {
            throw new RuntimeException("Booking lock for stylist {$stylistId} not found");
        }
    }

    public function findIdempotentBookingId(
        Stylist $stylist,
        string $idempotencyKey,
        string $requestHash,
    ): ?string
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT booking_id, request_hash FROM barbershop_booking_idempotency_keys WHERE stylist_id = ? AND idempotency_key = ?',
            [$stylist->getId()->toString(), $idempotencyKey],
        );

        if ($row === false) {
            return null;
        }

        if ($row['request_hash'] !== $requestHash) {
            throw new DomainException('Idempotency key was already used with different booking data.');
        }

        return (string) $row['booking_id'];
    }

    public function saveIdempotencyKey(
        Stylist $stylist,
        string $idempotencyKey,
        string $correlationId,
        string $requestHash,
        Booking $booking,
    ): void
    {
        $this->em->getConnection()->insert('barbershop_booking_idempotency_keys', [
            'stylist_id'       => $stylist->getId()->toString(),
            'idempotency_key'  => $idempotencyKey,
            'correlation_id'   => $correlationId,
            'booking_id'       => $booking->getId()->toString(),
            'request_hash'     => $requestHash,
            'created_at'       => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function save(Booking $booking): void
    {
        $this->em->persist($booking);
        $this->em->flush();
    }
}
