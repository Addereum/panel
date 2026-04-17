<?php

namespace App\Listeners\Server;

use App\Events\Server\BackupCompleted;
use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Notifications\BackupCompleted as BackupCompletedNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class BackupCompletedListener
{
    public function handle(BackupCompleted $event): void
    {
        $event->backup->server->loadMissing('user');

        $locale = $event->backup->server->user->language ?? 'en';
        $statusKey = $event->successful ? 'backup_completed' : 'backup_failed';

        Notification::make()
            ->status($event->successful ? 'success' : 'danger')
            ->title(trans("notifications.{$statusKey}", locale: $locale))
            ->body(trans('server/setting.server_info.server_name', ['name' => $event->backup->server->name], $locale))
            ->actions([
                Action::make('exclude_view')
                    ->button()
                    ->label(trans('notifications.open_server', locale: $locale))
                    ->markAsRead()
                    ->url(fn () => ListBackups::getUrl(panel: 'server', tenant: $event->backup->server)),
            ])
            ->sendToDatabase($event->backup->server->user);

        if (config()->get('panel.email.send_backup_complete_notification', true)) {
            $event->backup->server->user->notify(new BackupCompletedNotification($event->backup, $event->successful));
        }
    }
}
