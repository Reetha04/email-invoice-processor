<?php
// bootstrap/app.php

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
    ->withCommands([
        // ✅ Register your custom commands here
          App\Console\Commands\OneDriveSync::class,
        // App\Console\Commands\OneDriveSyncAllCountries::class,
        // App\Console\Commands\OneDriveSyncFuture::class,
        // App\Console\Commands\OneDriveProcessStaging::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        
        // ✅ Fetch emails every 5 minutes
        $schedule->command('emails:fetch')->everyFiveMinutes();
        
        // ✅ Fetch PnL emails every 5 minutes
        $schedule->command('pnl:fetch')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pnl_fetch.log'));
        
        // ✅ ONE DRIVE SYNC - ALL COUNTRIES (Every 30 minutes)
        // $schedule->command('onedrive:sync-all')
        //     ->everyThirtyMinutes()
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/onedrive_all.log'));
        
        // Process staging every 10 minutes
        // $schedule->command('onedrive:process')
        //     ->everyTenMinutes()
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/onedrive_process.log'));
        
        // ✅ Daily Report at 11:59 PM
        // $schedule->command('report:daily --upload')
        //     ->dailyAt('23:59')
        //     ->timezone('Asia/Kolkata')
        //     ->appendOutputTo(storage_path('logs/daily_report.log'));
        
        // // ✅ Daily P&L Report at 11:59 PM
        // $schedule->command('pnl:report:daily --upload')
        //     ->dailyAt('23:59')
        //     ->timezone('Asia/Kolkata')
        //     ->appendOutputTo(storage_path('logs/daily_pnl_report.log'));
         $schedule->command('onedrive:sync --country=MY')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/onedrive_my.log'));
    })
    
    ->create();