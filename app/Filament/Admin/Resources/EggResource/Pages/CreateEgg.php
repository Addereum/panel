<?php

namespace App\Filament\Admin\Resources\EggResource\Pages;

use AbdelhamidErrahmouni\FilamentMonacoEditor\MonacoEditor;
use App\Filament\Admin\Resources\EggResource;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CreateEgg extends CreateRecord
{
    protected static string $resource = EggResource::class;

    protected static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()->formId('form'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make()->tabs([
                    Tab::make('Configuration')
                        ->columns(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 4])
                        ->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->helperText('A simple, human-readable name to use as an identifier for this Egg.'),
                            TextInput::make('author')
                                ->maxLength(255)
                                ->required()
                                ->email()
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->helperText('The author of this version of the Egg.'),
                            Textarea::make('description')
                                ->rows(3)
                                ->columnSpanFull()
                                ->helperText('A description of this Egg that will be displayed throughout the Panel as needed.'),
                            Textarea::make('startup')
                                ->rows(3)
                                ->columnSpanFull()
                                ->required()
                                ->placeholder(implode("\n", [
                                    'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}}',
                                ]))
                                ->helperText('The default startup command that should be used for new servers using this Egg.'),
                            TagsInput::make('file_denylist')
                                ->placeholder('denied-file.txt')
                                ->helperText('A list of files that the end user is not allowed to edit.')
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),
                            TagsInput::make('features')
                                ->placeholder('Add Feature')
                                ->helperText('')
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),
                            Toggle::make('force_outgoing_ip')
                                ->hintIcon('tabler-question-mark')
                                ->hintIconTooltip("Forces all outgoing network traffic to have its Source IP NATed to the IP of the server's primary allocation IP.
                                    Required for certain games to work properly when the Node has multiple public IP addresses.
                                    Enabling this option will disable internal networking for any servers using this egg, causing them to be unable to internally access other servers on the same node."),
                            Hidden::make('script_is_privileged')
                                ->default(1),
                            TagsInput::make('tags')
                                ->placeholder('Add Tags')
                                ->helperText('')
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2]),
                            TextInput::make('update_url')
                                ->hintIcon('tabler-question-mark')
                                ->hintIconTooltip('URLs must point directly to the raw .json file.')
                                ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2, 'lg' => 2])
                                ->url(),
                            KeyValue::make('docker_images')
                                ->live()
                                ->columnSpanFull()
                                ->required()
                                ->addActionLabel('Add Image')
                                ->keyLabel('Name')
                                ->keyPlaceholder('Java 21')
                                ->valueLabel('Image URI')
                                ->valuePlaceholder('ghcr.io/parkervcp/yolks:java_21')
                                ->helperText('The docker images available to servers using this egg.'),
                        ]),

                    Tab::make('Prozessverwaltung')
                        ->columns()
                        ->schema([
                            Hidden::make('config_from')
                                ->default(null)
                                ->label('Einstellungen kopieren von')
                                // ->placeholder('None')
                                // ->relationship('configFrom', 'name', ignoreRecord: true)
                                ->helperText('Wähle eine andere Egg-Vorlage aus, um Standardeinstellungen zu übernehmen.'),
                            TextInput::make('config_stop')
                                ->required()
                                ->maxLength(255)
                                ->label('Stopp-Befehl')
                                ->helperText('Der Befehl, der an Serverprozesse gesendet werden soll, um sie ordnungsgemäß zu stoppen. Wenn du ein SIGINT senden musst, gib hier ^C ein.'),
                            Textarea::make('config_startup')->rows(10)->json()
                                ->label('Startkonfiguration')
                                ->default('{}')
                                ->helperText('Liste von Werten, nach denen der Daemon beim Starten eines Servers suchen soll, um den Abschluss zu bestimmen.'),
                            Textarea::make('config_files')->rows(10)->json()
                                ->label('Konfigurationsdateien')
                                ->default('{}')
                                ->helperText('Dies sollte eine JSON-Darstellung von Konfigurationsdateien sein, die geändert werden sollen, und welche Teile geändert werden müssen.'),
                            Textarea::make('config_logs')->rows(10)->json()
                                ->label('Log-Konfiguration')
                                ->default('{}')
                                ->helperText('Dies sollte eine JSON-Darstellung der Speicherorte von Logdateien und der Konfiguration benutzerdefinierter Logs durch den Daemon sein.'),
                        ]),
                    Tab::make('Egg-Variablen')
                        ->columnSpanFull()
                        ->schema([
                            Repeater::make('variables')
                                ->label('')
                                ->addActionLabel('Neue Egg-Variable hinzufügen')
                                ->grid()
                                ->relationship('variables')
                                ->name('name')
                                ->reorderable()->orderColumn()
                                ->collapsible()->collapsed()
                                ->columnSpan(2)
                                ->defaultItems(0)
                                ->itemLabel(fn (array $state) => $state['name'])
                                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                    $data['default_value'] ??= '';
                                    $data['description'] ??= '';
                                    $data['rules'] ??= [];
                                    $data['user_viewable'] ??= '';
                                    $data['user_editable'] ??= '';

                                    return $data;
                                })
                                ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                    $data['default_value'] ??= '';
                                    $data['description'] ??= '';
                                    $data['rules'] ??= [];
                                    $data['user_viewable'] ??= '';
                                    $data['user_editable'] ??= '';

                                    return $data;
                                })
                                ->schema([
                                    TextInput::make('name')
                                        ->live()
                                        ->debounce(750)
                                        ->maxLength(255)
                                        ->columnSpanFull()
                                        ->afterStateUpdated(fn (Set $set, $state) => $set('env_variable', str($state)->trim()->snake()->upper()->toString()))
                                        ->unique(modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('egg_id', $get('../../id')), ignoreRecord: true)
                                        ->validationMessages([
                                            'unique' => 'Eine Variable mit diesem Namen existiert bereits.',
                                        ])
                                        ->required(),
                                    Textarea::make('description')->columnSpanFull()->label('Beschreibung'),
                                    TextInput::make('env_variable')
                                        ->label('Umgebungsvariable')
                                        ->maxLength(255)
                                        ->prefix('{{')
                                        ->suffix('}}')
                                        ->hintIcon('tabler-code')
                                        ->hintIconTooltip(fn ($state) => "{{{$state}}}")
                                        ->unique(modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('egg_id', $get('../../id')), ignoreRecord: true)
                                        ->validationMessages([
                                            'unique' => 'Eine Variable mit diesem Namen existiert bereits.',
                                        ])
                                        ->required(),
                                    TextInput::make('default_value')->label('Standardwert')->maxLength(255),
                                    Fieldset::make('Benutzerberechtigungen')
                                        ->schema([
                                            Checkbox::make('user_viewable')->label('Sichtbar'),
                                            Checkbox::make('user_editable')->label('Bearbeitbar'),
                                        ]),
                                    TagsInput::make('rules')
                                        ->columnSpanFull()
                                        ->placeholder('Regel hinzufügen')
                                        ->reorderable()
                                        ->suggestions([
                                            'required',
                                            'nullable',
                                            'string',
                                            'integer',
                                            'numeric',
                                            'boolean',
                                            'alpha',
                                            'alpha_dash',
                                            'alpha_num',
                                            'url',
                                            'email',
                                            'regex:',
                                            'min:',
                                            'max:',
                                            'between:',
                                            'between:1024,65535',
                                            'in:',
                                            'in:true,false',
                                        ]),
                                ]),
                        ]),

                    Tab::make('Installationsskript')
                        ->columns(3)
                        ->schema([
                            Hidden::make('copy_script_from'),
                            //->placeholder('None')
                            //->relationship('scriptFrom', 'name', ignoreRecord: true),

                            TextInput::make('script_container')
                                ->required()
                                ->maxLength(255)
                                ->label('Skript-Container')
                                ->default('ghcr.io/pelican-eggs/installers:debian'),

                            Select::make('script_entry')
                                ->label('Skripteintrag')
                                ->selectablePlaceholder(false)
                                ->default('bash')
                                ->options(['bash', 'ash', '/bin/bash'])
                                ->required(),

                            MonacoEditor::make('script_install')
                                ->label('Installationsskript')
                                ->columnSpanFull()
                                ->fontSize('16px')
                                ->language('shell')
                                ->lazy()
                                ->view('filament.plugins.monaco-editor'),
                        ]),


                ])->columnSpanFull()->persistTabInQueryString(),
            ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['uuid'] ??= Str::uuid()->toString();

        if (is_array($data['config_startup'])) {
            $data['config_startup'] = json_encode($data['config_startup']);
        }

        if (is_array($data['config_logs'])) {
            $data['config_logs'] = json_encode($data['config_logs']);
        }

        logger()->info('new egg', $data);

        return parent::handleRecordCreation($data);
    }
}
