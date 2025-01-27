<?php

namespace App\Filament\Admin\Pages;

use App\Extensions\OAuth\Providers\OAuthProvider;
use App\Models\Backup;
use App\Notifications\MailTested;
use App\Traits\EnvironmentWriterTrait;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithHeaderActions;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification as MailNotification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * @property Form $form
 */
class Settings extends Page implements HasForms
{
    use EnvironmentWriterTrait;
    use InteractsWithForms;
    use InteractsWithHeaderActions;

    protected static ?string $navigationIcon = 'tabler-settings';

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view settings');
    }

    protected function getFormSchema(): array
    {
        return [
            Tabs::make('Tabs')
                ->columns()
                ->persistTabInQueryString()
                ->disabled(fn () => !auth()->user()->can('update settings'))
                ->tabs([
                    Tab::make('general')
                        ->label('General')
                        ->icon('tabler-home')
                        ->schema($this->generalSettings()),
                    Tab::make('captcha')
                        ->label('Captcha')
                        ->icon('tabler-shield')
                        ->schema($this->captchaSettings())
                        ->columns(3),
                    Tab::make('mail')
                        ->label('Mail')
                        ->icon('tabler-mail')
                        ->schema($this->mailSettings()),
                    Tab::make('backup')
                        ->label('Backup')
                        ->icon('tabler-box')
                        ->schema($this->backupSettings()),
                    Tab::make('OAuth')
                        ->label('OAuth')
                        ->icon('tabler-brand-oauth')
                        ->schema($this->oauthSettings()),
                    Tab::make('misc')
                        ->label('Sonstiges')
                        ->icon('tabler-tool')
                        ->schema($this->miscSettings()),
                ]),
        ];
    }

    private function generalSettings(): array
    {
        return [
            TextInput::make('APP_NAME')
                ->label('Name der App')
                ->required()
                ->default(env('APP_NAME', 'Pelican')),
            TextInput::make('APP_FAVICON')
                ->label('App Favicon')
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip('Favicons should be placed in the public folder, located in the root panel directory.')
                ->required()
                ->default(env('APP_FAVICON', '/pelican.ico')),
            Toggle::make('APP_DEBUG')
                ->label('Enable Debug Mode?')
                ->inline(false)
                ->onIcon('tabler-check')
                ->offIcon('tabler-x')
                ->onColor('success')
                ->offColor('danger')
                ->formatStateUsing(fn ($state): bool => (bool) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('APP_DEBUG', (bool) $state))
                ->default(env('APP_DEBUG', config('app.debug'))),
            ToggleButtons::make('FILAMENT_TOP_NAVIGATION')
                ->label('Navigation')
                ->inline()
                ->options([
                    false => 'Sidebar',
                    true => 'Topbar',
                ])
                ->formatStateUsing(fn ($state): bool => (bool) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('FILAMENT_TOP_NAVIGATION', (bool) $state))
                ->default(env('FILAMENT_TOP_NAVIGATION', config('panel.filament.top-navigation'))),
            ToggleButtons::make('PANEL_USE_BINARY_PREFIX')
                ->label('Einheitenpräfix')
                ->inline()
                ->options([
                    false => 'Dezimalpräfix (MB/ GB)',
                    true => 'Binärpräfix (MiB/ GiB)',
                ])
                ->formatStateUsing(fn ($state): bool => (bool) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('PANEL_USE_BINARY_PREFIX', (bool) $state))
                ->default(env('PANEL_USE_BINARY_PREFIX', config('panel.use_binary_prefix'))),
            ToggleButtons::make('APP_2FA_REQUIRED')
                ->label('2FA-Anforderung')
                ->inline()
                ->options([
                    0 => 'Nicht erforderlich',
                    1 => 'Erforderlich für Administratoren',
                    2 => 'Erforderlich für alle Benutzer',
                ])
                ->formatStateUsing(fn ($state): int => (int) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('APP_2FA_REQUIRED', (int) $state))
                ->default(env('APP_2FA_REQUIRED', config('panel.auth.2fa_required'))),
            TagsInput::make('TRUSTED_PROXIES')
                ->label('Vertrauenswürdige Proxys')
                ->separator()
                ->splitKeys(['Tab', ' '])
                ->placeholder('Neue IP oder IP-Bereich')
                ->default(env('TRUSTED_PROXIES', implode(',', config('trustedproxy.proxies'))))
                ->hintActions([
                    FormAction::make('clear')
                        ->label('Löschen')
                        ->color('danger')
                        ->icon('tabler-trash')
                        ->requiresConfirmation()
                        ->authorize(fn () => auth()->user()->can('update settings'))
                        ->action(fn (Set $set) => $set('TRUSTED_PROXIES', [])),
                    FormAction::make('cloudflare')
                        ->label('Auf Cloudflare IPs einstellen')
                        ->icon('tabler-brand-cloudflare')
                        ->authorize(fn () => auth()->user()->can('update settings'))
                        ->action(function (Factory $client, Set $set) {
                            $ips = collect();

                            try {
                                $response = $client
                                    ->timeout(3)
                                    ->connectTimeout(3)
                                    ->get('https://api.cloudflare.com/client/v4/ips');

                                if ($response->getStatusCode() === 200) {
                                    $result = $response->json('result');
                                    foreach (['ipv4_cidrs', 'ipv6_cidrs'] as $value) {
                                        $ips->push(...data_get($result, $value));
                                    }
                                    $ips->unique();
                                }
                            } catch (Exception) {
                            }

                            $set('TRUSTED_PROXIES', $ips->values()->all());
                        }),
                ]),
            Select::make('FILAMENT_WIDTH')
                ->label('Anzeigebreite')
                ->native(false)
                ->options(MaxWidth::class)
                ->default(env('FILAMENT_WIDTH', config('panel.filament.display-width'))),
        ];
    }

    private function captchaSettings(): array
    {
        return [
            Toggle::make('TURNSTILE_ENABLED')
                ->label('Turnstile Captcha aktivieren?')
                ->inline(false)
                ->columnSpan(1)
                ->onIcon('tabler-check')
                ->offIcon('tabler-x')
                ->onColor('success')
                ->offColor('danger')
                ->live()
                ->formatStateUsing(fn ($state): bool => (bool) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('TURNSTILE_ENABLED', (bool) $state))
                ->default(env('TURNSTILE_ENABLED', config('turnstile.turnstile_enabled'))),
            Placeholder::make('info')
                ->columnSpan(2)
                ->content(new HtmlString('<p>You can generate the keys on your <u><a href="https://developers.cloudflare.com/turnstile/get-started/#get-a-sitekey-and-secret-key" target="_blank">Cloudflare Dashboard</a></u>. A Cloudflare account is required.</p>')),
            TextInput::make('TURNSTILE_SITE_KEY')
                ->label('Site Key')
                ->required()
                ->visible(fn (Get $get) => $get('TURNSTILE_ENABLED'))
                ->default(env('TURNSTILE_SITE_KEY', config('turnstile.turnstile_site_key')))
                ->placeholder('1x00000000000000000000AA'),
            TextInput::make('TURNSTILE_SECRET_KEY')
                ->label('Secret Key')
                ->required()
                ->visible(fn (Get $get) => $get('TURNSTILE_ENABLED'))
                ->default(env('TURNSTILE_SECRET_KEY', config('turnstile.secret_key')))
                ->placeholder('1x0000000000000000000000000000000AA'),
            Toggle::make('TURNSTILE_VERIFY_DOMAIN')
                ->label('Verifiziere Domain?')
                ->inline(false)
                ->onIcon('tabler-check')
                ->offIcon('tabler-x')
                ->onColor('success')
                ->offColor('danger')
                ->visible(fn (Get $get) => $get('TURNSTILE_ENABLED'))
                ->formatStateUsing(fn ($state): bool => (bool) $state)
                ->afterStateUpdated(fn ($state, Set $set) => $set('TURNSTILE_VERIFY_DOMAIN', (bool) $state))
                ->default(env('TURNSTILE_VERIFY_DOMAIN', config('turnstile.turnstile_verify_domain'))),
        ];
    }

    private function mailSettings(): array
    {
        return [
            ToggleButtons::make('MAIL_MAILER')
                ->label('Mail-Treiber')
                ->columnSpanFull()
                ->inline()
                ->options([
                    'log' => '/storage/logs Directory',
                    'smtp' => 'SMTP Server',
                    'mailgun' => 'Mailgun',
                    'mandrill' => 'Mandrill',
                    'postmark' => 'Postmark',
                    'sendmail' => 'sendmail (PHP)',
                ])
                ->live()
                ->default(env('MAIL_MAILER', config('mail.default')))
                ->hintAction(
                    FormAction::make('test')
                        ->label('Test Mail schicken')
                        ->icon('tabler-send')
                        ->hidden(fn (Get $get) => $get('MAIL_MAILER') === 'log')
                        ->authorize(fn () => auth()->user()->can('update settings'))
                        ->action(function (Get $get) {
                            // Store original mail configuration
                            $originalConfig = [
                                'mail.default' => config('mail.default'),
                                'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
                                'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
                                'mail.mailers.smtp.username' => config('mail.mailers.smtp.username'),
                                'mail.mailers.smtp.password' => config('mail.mailers.smtp.password'),
                                'mail.mailers.smtp.encryption' => config('mail.mailers.smtp.encryption'),
                                'mail.from.address' => config('mail.from.address'),
                                'mail.from.name' => config('mail.from.name'),
                                'services.mailgun.domain' => config('services.mailgun.domain'),
                                'services.mailgun.secret' => config('services.mailgun.secret'),
                                'services.mailgun.endpoint' => config('services.mailgun.endpoint'),
                            ];

                            try {
                                // Update mail configuration dynamically
                                config([
                                    'mail.default' => $get('MAIL_MAILER'),
                                    'mail.mailers.smtp.host' => $get('MAIL_HOST'),
                                    'mail.mailers.smtp.port' => $get('MAIL_PORT'),
                                    'mail.mailers.smtp.username' => $get('MAIL_USERNAME'),
                                    'mail.mailers.smtp.password' => $get('MAIL_PASSWORD'),
                                    'mail.mailers.smtp.encryption' => $get('MAIL_ENCRYPTION'),
                                    'mail.from.address' => $get('MAIL_FROM_ADDRESS'),
                                    'mail.from.name' => $get('MAIL_FROM_NAME'),
                                    'services.mailgun.domain' => $get('MAILGUN_DOMAIN'),
                                    'services.mailgun.secret' => $get('MAILGUN_SECRET'),
                                    'services.mailgun.endpoint' => $get('MAILGUN_ENDPOINT'),
                                ]);

                                MailNotification::route('mail', auth()->user()->email)
                                    ->notify(new MailTested(auth()->user()));

                                Notification::make()
                                    ->title('Test Mail gesendet')
                                    ->success()
                                    ->send();
                            } catch (Exception $exception) {
                                Notification::make()
                                    ->title('Test Mail gescheitert')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            } finally {
                                config($originalConfig);
                            }
                        })
                ),
            Section::make('"From" Settings')
                ->description('Legen Sie die Adresse und den Namen fest, die als "Von" in E-Mails verwendet werden.')
                ->columns()
                ->schema([
                    TextInput::make('MAIL_FROM_ADDRESS')
                        ->label('Von Adresse')
                        ->required()
                        ->email()
                        ->default(env('MAIL_FROM_ADDRESS', config('mail.from.address'))),
                    TextInput::make('MAIL_FROM_NAME')
                        ->label('Von Name')
                        ->required()
                        ->default(env('MAIL_FROM_NAME', config('mail.from.name'))),
                ]),
            Section::make('SMTP Configuration')
                ->columns()
                ->visible(fn (Get $get) => $get('MAIL_MAILER') === 'smtp')
                ->schema([
                    TextInput::make('MAIL_HOST')
                        ->label('Host')
                        ->required()
                        ->default(env('MAIL_HOST', config('mail.mailers.smtp.host'))),
                    TextInput::make('MAIL_PORT')
                        ->label('Port')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->default(env('MAIL_PORT', config('mail.mailers.smtp.port'))),
                    TextInput::make('MAIL_USERNAME')
                        ->label('Benutzername')
                        ->default(env('MAIL_USERNAME', config('mail.mailers.smtp.username'))),
                    TextInput::make('MAIL_PASSWORD')
                        ->label('Passwort')
                        ->password()
                        ->revealable()
                        ->default(env('MAIL_PASSWORD')),
                    ToggleButtons::make('MAIL_ENCRYPTION')
                        ->label('Verschlüsselung')
                        ->inline()
                        ->options([
                            'tls' => 'TLS',
                            'ssl' => 'SSL',
                            '' => 'None',
                        ])
                        ->default(env('MAIL_ENCRYPTION', config('mail.mailers.smtp.encryption', 'tls')))
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $port = match ($state) {
                                'tls' => 587,
                                'ssl' => 465,
                                default => 25,
                            };
                            $set('MAIL_PORT', $port);
                        }),
                ]),
            Section::make('Mailgun Konfiguration')
                ->columns()
                ->visible(fn (Get $get) => $get('MAIL_MAILER') === 'mailgun')
                ->schema([
                    TextInput::make('MAILGUN_DOMAIN')
                        ->label('Domain')
                        ->required()
                        ->default(env('MAILGUN_DOMAIN', config('services.mailgun.domain'))),
                    TextInput::make('MAILGUN_SECRET')
                        ->label('Secret')
                        ->required()
                        ->default(env('MAILGUN_SECRET', config('services.mailgun.secret'))),
                    TextInput::make('MAILGUN_ENDPOINT')
                        ->label('Endpoint')
                        ->required()
                        ->default(env('MAILGUN_ENDPOINT', config('services.mailgun.endpoint'))),
                ]),
        ];
    }

    private function backupSettings(): array
    {
        return [
            ToggleButtons::make('APP_BACKUP_DRIVER')
                ->label('Backup-Treiber')
                ->columnSpanFull()
                ->inline()
                ->options([
                    Backup::ADAPTER_DAEMON => 'Wings',
                    Backup::ADAPTER_AWS_S3 => 'S3',
                ])
                ->live()
                ->default(env('APP_BACKUP_DRIVER', config('backups.default'))),
            Section::make('Throttles')
                ->description('Konfigurieren Sie, wie viele Backups in einem Zeitraum erstellt werden können. Setzen Sie den Zeitraum auf 0, um diese Begrenzung zu deaktivieren.')
                ->columns()
                ->schema([
                    TextInput::make('BACKUP_THROTTLE_LIMIT')
                        ->label('Limit')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->default(config('backups.throttles.limit')),
                    TextInput::make('BACKUP_THROTTLE_PERIOD')
                        ->label('Zeitraum')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->suffix('Seconds')
                        ->default(config('backups.throttles.period')),
                ]),
            Section::make('S3 Configuration')
                ->columns()
                ->visible(fn (Get $get) => $get('APP_BACKUP_DRIVER') === Backup::ADAPTER_AWS_S3)
                ->schema([
                    TextInput::make('AWS_DEFAULT_REGION')
                        ->label('Standardregion')
                        ->required()
                        ->default(config('backups.disks.s3.region')),
                    TextInput::make('AWS_ACCESS_KEY_ID')
                        ->label('Zugriffsschlüssel-ID')
                        ->required()
                        ->default(config('backups.disks.s3.key')),
                    TextInput::make('AWS_SECRET_ACCESS_KEY')
                        ->label('Geheimer Zugriffsschlüssel')
                        ->required()
                        ->default(config('backups.disks.s3.secret')),
                    TextInput::make('AWS_BACKUPS_BUCKET')
                        ->label('Bucket')
                        ->required()
                        ->default(config('backups.disks.s3.bucket')),
                    TextInput::make('AWS_ENDPOINT')
                        ->label('Endpunkt')
                        ->required()
                        ->default(config('backups.disks.s3.endpoint')),
                    Toggle::make('AWS_USE_PATH_STYLE_ENDPOINT')
                        ->label('Pfadstil-Endpunkt verwenden?')
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('AWS_USE_PATH_STYLE_ENDPOINT', (bool) $state))
                        ->default(env('AWS_USE_PATH_STYLE_ENDPOINT', config('backups.disks.s3.use_path_style_endpoint'))),
                ]),
        ];
    }

    private function oauthSettings(): array
    {
        $formFields = [];

        $oauthProviders = OAuthProvider::get();
        foreach ($oauthProviders as $oauthProvider) {
            $id = Str::upper($oauthProvider->getId());
            $name = Str::title($oauthProvider->getId());

            $formFields[] = Section::make($name)
                ->columns(5)
                ->icon($oauthProvider->getIcon() ?? 'tabler-brand-oauth')
                ->collapsed(fn () => !env("OAUTH_{$id}_ENABLED", false))
                ->collapsible()
                ->schema([
                    Hidden::make("OAUTH_{$id}_ENABLED")
                        ->live()
                        ->default(env("OAUTH_{$id}_ENABLED")),
                    Actions::make([
                        FormAction::make("disable_oauth_$id")
                            ->visible(fn (Get $get) => $get("OAUTH_{$id}_ENABLED"))
                            ->label('Disable')
                            ->color('danger')
                            ->action(function (Set $set) use ($id) {
                                $set("OAUTH_{$id}_ENABLED", false);
                            }),
                        FormAction::make("enable_oauth_$id")
                            ->visible(fn (Get $get) => !$get("OAUTH_{$id}_ENABLED"))
                            ->label('Enable')
                            ->color('success')
                            ->steps($oauthProvider->getSetupSteps())
                            ->modalHeading("Enable $name")
                            ->modalSubmitActionLabel('Enable')
                            ->modalCancelAction(false)
                            ->action(function ($data, Set $set) use ($id) {
                                $data = array_merge([
                                    "OAUTH_{$id}_ENABLED" => 'true',
                                ], $data);

                                $data = array_filter($data, fn ($value) => !Str::startsWith($value, '_noenv'));

                                foreach ($data as $key => $value) {
                                    $set($key, $value);
                                }
                            }),
                    ])->columnSpan(1),
                    Group::make($oauthProvider->getSettingsForm())
                        ->visible(fn (Get $get) => $get("OAUTH_{$id}_ENABLED"))
                        ->columns(4)
                        ->columnSpan(4),
                ]);
        }

        return $formFields;
    }

    private function miscSettings(): array
    {
        return [
            Section::make('Automatische Zuordnungserstellung')
                ->description('Aktivieren oder deaktivieren Sie, ob Benutzer Zuordnungen über den Kundenbereich erstellen können.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('PANEL_CLIENT_ALLOCATIONS_ENABLED')
                        ->label('Benutzern das Erstellen von Zuordnungen erlauben?')
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpanFull()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('PANEL_CLIENT_ALLOCATIONS_ENABLED', (bool) $state))
                        ->default(env('PANEL_CLIENT_ALLOCATIONS_ENABLED', config('panel.client_features.allocations.enabled'))),
                    TextInput::make('PANEL_CLIENT_ALLOCATIONS_RANGE_START')
                        ->label('Start-Port')
                        ->required()
                        ->numeric()
                        ->minValue(1024)
                        ->maxValue(65535)
                        ->visible(fn (Get $get) => $get('PANEL_CLIENT_ALLOCATIONS_ENABLED'))
                        ->default(env('PANEL_CLIENT_ALLOCATIONS_RANGE_START')),
                    TextInput::make('PANEL_CLIENT_ALLOCATIONS_RANGE_END')
                        ->label('End-Port')
                        ->required()
                        ->numeric()
                        ->minValue(1024)
                        ->maxValue(65535)
                        ->visible(fn (Get $get) => $get('PANEL_CLIENT_ALLOCATIONS_ENABLED'))
                        ->default(env('PANEL_CLIENT_ALLOCATIONS_RANGE_END')),
                ]),
            Section::make('E-Mail-Benachrichtigungen')
                ->description('Legen Sie fest, welche E-Mail-Benachrichtigungen an Benutzer gesendet werden sollen.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('PANEL_SEND_INSTALL_NOTIFICATION')
                        ->label('Server installiert')
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpanFull()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('PANEL_SEND_INSTALL_NOTIFICATION', (bool) $state))
                        ->default(env('PANEL_SEND_INSTALL_NOTIFICATION', config('panel.email.send_install_notification'))),
                    Toggle::make('PANEL_SEND_REINSTALL_NOTIFICATION')
                        ->label('Server neu installiert')
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpanFull()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('PANEL_SEND_REINSTALL_NOTIFICATION', (bool) $state))
                        ->default(env('PANEL_SEND_REINSTALL_NOTIFICATION', config('panel.email.send_reinstall_notification'))),
                ]),

            Section::make('Verbindungen')
                ->description('Zeitüberschreitungen für Anfragen.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('GUZZLE_TIMEOUT')
                        ->label('Anfrage-Timeout')
                        ->required()
                        ->numeric()
                        ->minValue(15)
                        ->maxValue(60)
                        ->suffix('Sekunden')
                        ->default(env('GUZZLE_TIMEOUT', config('panel.guzzle.timeout'))),
                    TextInput::make('GUZZLE_CONNECT_TIMEOUT')
                        ->label('Verbindungs-Timeout')
                        ->required()
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(60)
                        ->suffix('Sekunden')
                        ->default(env('GUZZLE_CONNECT_TIMEOUT', config('panel.guzzle.connect_timeout'))),
                ]),
            Section::make('Aktivitätsprotokolle')
                ->description('Legen Sie fest, wie oft alte Aktivitätsprotokolle bereinigt werden sollen und ob Administratoraktivitäten protokolliert werden sollen.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('APP_ACTIVITY_PRUNE_DAYS')
                        ->label('Bereinigungsalter')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix('Tage')
                        ->default(env('APP_ACTIVITY_PRUNE_DAYS', config('activity.prune_days'))),
                    Toggle::make('APP_ACTIVITY_HIDE_ADMIN')
                        ->label('Admin-Aktivitäten ausblenden?')
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('APP_ACTIVITY_HIDE_ADMIN', (bool) $state))
                        ->default(env('APP_ACTIVITY_HIDE_ADMIN', config('activity.hide_admin_activity'))),
                ]),
            Section::make('API')
                ->description('Legt das Ratenlimit für die Anzahl der Anfragen pro Minute fest.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('APP_API_CLIENT_RATELIMIT')
                        ->label('API-Ratenlimit für Clients')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('Anfragen pro Minute')
                        ->default(env('APP_API_CLIENT_RATELIMIT', config('http.rate_limit.client'))),
                    TextInput::make('APP_API_APPLICATION_RATELIMIT')
                        ->label('API-Ratenlimit für Anwendungen')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->suffix('Anfragen pro Minute')
                        ->default(env('APP_API_APPLICATION_RATELIMIT', config('http.rate_limit.application'))),
                ]),
            Section::make('Server')
                ->description('Einstellungen für Server.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('PANEL_EDITABLE_SERVER_DESCRIPTIONS')
                        ->label('Benutzern erlauben, Serverbeschreibungen zu bearbeiten?')
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpanFull()
                        ->formatStateUsing(fn ($state): bool => (bool) $state)
                        ->afterStateUpdated(fn ($state, Set $set) => $set('PANEL_EDITABLE_SERVER_DESCRIPTIONS', (bool) $state))
                        ->default(env('PANEL_EDITABLE_SERVER_DESCRIPTIONS', config('panel.editable_server_descriptions'))),
                ]),
            Section::make('Webhook')
                ->description('Legen Sie fest, wie oft alte Webhook-Protokolle bereinigt werden sollen.')
                ->columns()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('APP_WEBHOOK_PRUNE_DAYS')
                        ->label('Bereinigungsalter')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->suffix('Tage')
                        ->default(env('APP_WEBHOOK_PRUNE_DAYS', config('panel.webhook.prune_days'))),
                ]),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            // Convert bools to a string, so they are correctly written to the .env file
            $data = array_map(fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value, $data);

            $this->writeToEnvironment($data);

            Artisan::call('config:clear');
            Artisan::call('queue:restart');

            $this->redirect($this->getUrl());

            Notification::make()
                ->title('Settings saved')
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()
                ->title('Save failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->action('save')
                ->authorize(fn () => auth()->user()->can('update settings'))
                ->keyBindings(['mod+s']),
        ];
    }
}
