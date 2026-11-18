<?php

namespace App\Filament\Resources\ProjectSyncs\Pages;

use App\Filament\Resources\ProjectSyncs\ProjectSyncResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectSync extends ViewRecord
{
    protected static string $resource = ProjectSyncResource::class;

    protected ?string $pollingInterval = '3s';
}
