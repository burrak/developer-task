<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use App\Application\TransactionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class DoctrineTransactionRunner implements TransactionRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function run(callable $callback): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $result = $callback();
            $connection->commit();

            return $result;
        } catch (Throwable $e) {
            if ($connection->getTransactionNestingLevel() > 0) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
