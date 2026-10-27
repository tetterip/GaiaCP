<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ClientPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ClientPanelProvider::class,
];
