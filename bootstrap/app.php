<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule; 

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('emails:fetch')->everyFiveMinutes();
       $schedule->command('pnl:fetch')
        ->everyFiveMinutes()
        ->withoutOverlapping()  // ✅ Prevents overlapping schedule runs
        ->appendOutputTo(storage_path('logs/pnl_fetch.log'));
        $schedule->command('report:daily --upload')
        ->dailyAt('23:59')
        ->timezone('Asia/Kolkata')
        ->appendOutputTo(storage_path('logs/daily_report.log'));
    
    // ✅ Daily P&L Report at 11:59 PM
    $schedule->command('pnl:report:daily --upload')
        ->dailyAt('23:59')
        ->timezone('Asia/Kolkata')
        ->appendOutputTo(storage_path('logs/daily_pnl_report.log'));
    })
    ->create();
