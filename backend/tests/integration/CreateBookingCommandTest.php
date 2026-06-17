<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Barbershop\Command\CreateBooking\CreateBookingCommand;
use App\Application\CommandBus;
use App\Bootstrap;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateBookingCommandTest extends TestCase
{
    private const STYLIST_ID = 'bbbbbbbb-bbbb-bbbb-bbbb-aaaaaaaaaaaa';
    private const SERVICE_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private static Container $container;
    private CommandBus $commandBus;
    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        self::$container = Bootstrap::boot();
    }

    protected function setUp(): void
    {
        $this->commandBus = self::$container->getByType(CommandBus::class);
        $this->em = self::$container->getByType(EntityManagerInterface::class);

        $connection = $this->em->getConnection();

        $fixturesLoaded = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM barbershop_stylists WHERE id = ?',
            [self::STYLIST_ID],
        ) === 1;
        self::assertTrue($fixturesLoaded, 'Fixtures are not loaded. Run migrations and fixtures before this test.');

        $connection->executeStatement('DELETE FROM barbershop_booking_idempotency_keys');
        $connection->executeStatement('DELETE FROM barbershop_bookings');
        $connection->executeStatement(
            'INSERT INTO barbershop_booking_locks (stylist_id, version)
            SELECT s.id, 0
            FROM barbershop_stylists s
            WHERE NOT EXISTS (
                SELECT 1 FROM barbershop_booking_locks l WHERE l.stylist_id = s.id
            )',
        );
    }

    #[Test]
    public function idempotentRetryDoesNotCreateDuplicateBooking(): void
    {
        $command = new CreateBookingCommand(
            stylistId:       self::STYLIST_ID,
            serviceId:       self::SERVICE_ID,
            startTime:       '2026-06-17 09:00:00',
            customerName:    'Race Tester',
            customerContact: 'race@example.com',
            idempotencyKey:  'booking-key-1',
            correlationId:   'correlation-1',
        );

        $first = $this->commandBus->dispatch($command);
        $retry = $this->commandBus->dispatch($command);

        self::assertSame($first->aggregateId, $retry->aggregateId);
        self::assertSame(1, $this->activeBookingCount());
        self::assertSame('correlation-1', $this->storedCorrelationId('booking-key-1'));
    }

    #[Test]
    public function idempotencyKeyCannotBeReusedWithDifferentPayload(): void
    {
        $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:00:00',
            customerName: 'Race Tester',
            customerContact: 'race@example.com',
            idempotencyKey: 'booking-key-1',
            correlationId: 'correlation-1',
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Idempotency key was already used');

        $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:00:00',
            customerName: 'Different Payload',
            customerContact: 'race@example.com',
            idempotencyKey: 'booking-key-1',
            correlationId: 'correlation-2',
        ));
    }

    #[Test]
    public function overlappingBookingIsRejected(): void
    {
        $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:00:00',
            customerName: 'Race Tester',
            customerContact: 'race@example.com',
            idempotencyKey: 'booking-key-1',
            correlationId: 'correlation-1',
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('selected time slot is no longer available');

        $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:15:00',
            customerName: 'Other Customer',
            customerContact: 'other@example.com',
            idempotencyKey: 'booking-key-2',
            correlationId: 'correlation-3',
        ));
    }

    #[Test]
    public function adjacentBookingCanBeCreated(): void
    {
        $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:00:00',
            customerName: 'Race Tester',
            customerContact: 'race@example.com',
            idempotencyKey: 'booking-key-1',
            correlationId: 'correlation-1',
        ));

        $next = $this->commandBus->dispatch($this->createCommand(
            startTime: '2026-06-17 09:30:00',
            customerName: 'Next Customer',
            customerContact: 'next@example.com',
            idempotencyKey: 'booking-key-3',
            correlationId: 'correlation-4',
        ));

        self::assertNotSame('', $next->aggregateId);
        self::assertSame(2, $this->activeBookingCount());
    }

    private function createCommand(
        string $startTime,
        string $customerName,
        string $customerContact,
        string $idempotencyKey,
        string $correlationId,
    ): CreateBookingCommand {
        return new CreateBookingCommand(
            stylistId:       self::STYLIST_ID,
            serviceId:       self::SERVICE_ID,
            startTime:       $startTime,
            customerName:    $customerName,
            customerContact: $customerContact,
            idempotencyKey:  $idempotencyKey,
            correlationId:   $correlationId,
        );
    }

    private function activeBookingCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM barbershop_bookings WHERE stylist_id = ? AND status != ?',
            [self::STYLIST_ID, 'rejected'],
        );
    }

    private function storedCorrelationId(string $idempotencyKey): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT correlation_id FROM barbershop_booking_idempotency_keys WHERE stylist_id = ? AND idempotency_key = ?',
            [self::STYLIST_ID, $idempotencyKey],
        );
    }
}
