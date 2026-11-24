@php
    $used = (int) ($getRecord()->used_bytes ?? 0);
@endphp
<div style="font-size:.85rem">{{ $used > 0 ? \App\Filament\Resources\Runs\Tables\RunsTable::bytes($used) : '0 Б' }}</div>
