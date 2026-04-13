<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\MyPickingTasks;
use App\Filament\Widgets\MyPutAwayTasks;
use Filament\Pages\Page;

class TaskDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static string $view = 'filament.pages.task-dashboard';
    protected static ?string $navigationGroup = 'My Tasks';
    protected static ?int $navigationSort = -1; // Tampilkan di paling atas

    /**
     * Daftarkan widget tabel yang akan ditampilkan di halaman ini.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MyPickingTasks::class,
            MyPutAwayTasks::class,
        ];
    }
}
