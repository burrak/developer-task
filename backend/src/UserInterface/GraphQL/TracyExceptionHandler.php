<?php

declare(strict_types=1);

namespace App\UserInterface\GraphQL;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

final class TracyExceptionHandler implements ExceptionHandler
{
    public function report(Throwable $e): void
    {
        Debugger::log($e, ILogger::ERROR);
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render($request, Throwable $e): never
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void
    {
        Debugger::log($e, ILogger::ERROR);
    }
}
