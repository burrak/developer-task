<?php

declare(strict_types=1);

namespace App\Application\Barbershop\Command\CreateBooking;

use App\Application\CommandResult;
use App\Application\TransactionRunner;
use App\Domain\Barbershop\Entity\Booking;
use App\Domain\Barbershop\Entity\Service;
use App\Domain\Barbershop\Entity\Stylist;
use App\Domain\Barbershop\Repository\BookingRepositoryInterface;
use App\Domain\ValueObject\UuidFactory;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final class CreateBookingCommandHandler
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookingRepository,
        private readonly EntityManagerInterface $em,
        private readonly UuidFactory $uuidFactory,
        private readonly TransactionRunner $transactionRunner,
    ) {}

    public function handle(CreateBookingCommand $command): CommandResult
    {
        $service = $this->em->find(Service::class, $this->uuidFactory->fromString($command->serviceId))
            ?? throw new DomainException("Service {$command->serviceId} not found");

        $stylist = $this->em->find(Stylist::class, $this->uuidFactory->fromString($command->stylistId))
            ?? throw new DomainException("Stylist {$command->stylistId} not found");

        $start = new DateTimeImmutable($command->startTime);
        $end   = $start->modify("+{$service->getDurationMinutes()} minutes");
        $requestHash = $this->createRequestHash($command, $start);

        return $this->transactionRunner->run(function () use ($stylist, $service, $start, $end, $command, $requestHash): CommandResult {
            $this->bookingRepository->lockStylistBookingWrites($stylist);

            $idempotentBookingId = $this->bookingRepository->findIdempotentBookingId(
                $stylist,
                $command->idempotencyKey,
                $requestHash,
            );
            if ($idempotentBookingId !== null) {
                return new CommandResult($idempotentBookingId);
            }

            if ($this->bookingRepository->hasActiveOverlappingBooking($stylist, $start, $end)) {
                throw new DomainException('The selected time slot is no longer available. Please choose another time.');
            }

            $booking = new Booking(
                $this->uuidFactory->generate(),
                $service,
                $stylist,
                $start,
                $end,
                $command->customerName,
                $command->customerContact,
            );

            $this->bookingRepository->save($booking);
            $this->bookingRepository->saveIdempotencyKey(
                $stylist,
                $command->idempotencyKey,
                $command->correlationId,
                $requestHash,
                $booking,
            );

            return new CommandResult($booking->getId()->toString());
        });
    }

    private function createRequestHash(CreateBookingCommand $command, DateTimeImmutable $start): string
    {
        return hash('sha256', json_encode([
            'stylistId'       => $command->stylistId,
            'serviceId'       => $command->serviceId,
            'startTime'       => $start->format(DateTimeInterface::ATOM),
            'customerName'    => $command->customerName,
            'customerContact' => $command->customerContact,
        ], JSON_THROW_ON_ERROR));
    }
}
