<?php

declare(strict_types=1);

namespace App\Application;

interface TransactionRunner
{
    public function run(callable $callback): mixed;
}
