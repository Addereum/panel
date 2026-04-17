<?php

namespace App\Notifications;

use App\Filament\Server\Resources\Backups\Pages\ListBackups;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Backup $backup, public bool $successful) {}

    /** @return string[] */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->language ?? 'en';
        $statusKey = $this->successful ? 'backup_completed' : 'backup_failed';

        return (new MailMessage())
            ->subject(trans("mail.{$statusKey}.subject", locale: $locale))
            ->greeting(trans('mail.greeting', ['name' => $notifiable->username], $locale))
            ->line(trans("mail.{$statusKey}.body", locale: $locale))
            ->line(trans("mail.{$statusKey}.server_name", ['name' => $this->backup->server->name], $locale))
            ->line(trans("mail.{$statusKey}.backup_name", ['name' => $this->backup->name], $locale))
            ->action(trans("mail.{$statusKey}.action", locale: $locale), ListBackups::getUrl(panel: 'server', tenant: $this->backup->server));
    }
}
