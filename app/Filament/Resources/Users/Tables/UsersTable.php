<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('role')->label('Роль')->badge()
                    ->formatStateUsing(fn ($state) => ($state instanceof UserRole ? $state : UserRole::tryFrom((string) $state))?->label() ?? '—')
                    ->color(fn ($state) => ($state instanceof UserRole ? $state : UserRole::tryFrom((string) $state)) === UserRole::Superuser ? 'warning' : 'gray'),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (User $record) => $record->id === auth()->id() || $record->isLastSuperuser()),
            ])
            ->defaultSort('id');
    }
}
