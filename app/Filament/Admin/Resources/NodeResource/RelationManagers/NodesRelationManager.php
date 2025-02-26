<?php

namespace App\Filament\Admin\Resources\NodeResource\RelationManagers;

use App\Models\Server;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NodesRelationManager extends RelationManager
{
    protected static string $relationship = 'servers';

    protected static ?string $icon = 'tabler-brand-docker';

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->columns([
                TextColumn::make('user.username')
                    ->label('Besitzer')
                    ->icon('tabler-user')
                    ->url(fn (Server $server): string => route('filament.admin.resources.users.edit', ['record' => $server->user]))
                    ->searchable(),
                TextColumn::make('name')
                    ->icon('tabler-brand-docker')
                    ->url(fn (Server $server): string => route('filament.admin.resources.servers.edit', ['record' => $server]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('egg.name')
                    ->icon('tabler-egg')
                    ->url(fn (Server $server): string => route('filament.admin.resources.eggs.edit', ['record' => $server->user]))
                    ->sortable(),
                SelectColumn::make('allocation.id')
                    ->label('Primäre Zuordnung')
                    ->options(fn (Server $server) => [$server->allocation->id => $server->allocation->address])
                    ->selectablePlaceholder(false)
                    ->sortable(),
                TextColumn::make('memory')->icon('tabler-device-desktop-analytics')->label('Arbeitsspeicher'),
                TextColumn::make('cpu')->icon('tabler-cpu')->label('CPU'),
                TextColumn::make('databases_count')
                    ->counts('databases')
                    ->label('Datenbanken')
                    ->icon('tabler-database')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('backups_count')
                    ->counts('backups')
                    ->label('Backups')
                    ->icon('tabler-file-download')
                    ->numeric()
                    ->sortable(),
            ]);
    }
}
