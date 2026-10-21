<?php


use App\Models\Design;
use App\Models\Driver;
use App\Models\DesignDriver;
use App\Enums\DesignCategoryEnum;
use App\Filament\Forms\MoneyInput;
use App\Jobs\BuildDesignSnapshot;
use Illuminate\Support\Str;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\{Actions\Action,
    Columns\ToggleColumn,
    Table,
    Concerns\InteractsWithTable,
    Actions\CreateAction,
    Actions\DeleteAction,
    Actions\EditAction,
    Actions\ViewAction,
    Columns\TextColumn
};
use FilamentTiptapEditor\Enums\TiptapOutput;
use FilamentTiptapEditor\TiptapEditor;
use Livewire\Volt\Component;
use function Laravel\Folio\{middleware, name};

middleware('auth');
name('dashboard.designs');

new class extends Component implements HasForms, Tables\Contracts\HasTable {

    use InteractsWithForms, InteractsWithTable;

    public ?array $data = [];

    public Driver $driver;

    public $speakerTaglines = [
        "Experience Sound in Its Purest Form",
        "Where Music Comes to Life",
        "Precision Audio Engineering",
        "Unleash the Power of Perfect Sound",
        "Crafted for Audio Excellence",
        "Sound that Moves You",
        "Beyond Ordinary Listening",
        "The Art of Audio Perfection",
        "Sonic Innovation Redefined",
        "Elevate Your Audio Experience",
        "Performance Meets Precision",
        "Sound Without Compromise",
        "Engineered for Excellence",
        "The Future of Sound is Here",
        "Audio Mastery Unleashed",
        "Where Technology Meets Harmony",
        "Experience Sound in 360°",
        "Pure. Powerful. Precise.",
        "Revolutionary Sound Design",
        "Setting New Audio Standards",
        "Immersive Sound Experience",
        "The Evolution of Audio",
        "Sound Engineering Excellence",
        "Redefining Audio Clarity",
        "Premium Sound Engineering"
    ];

    // Files live under designs/{storage_key}/..., where storage_key is a UUID filled in when the
    // create form opens (so renaming a design never moves its files). Source files go on the
    // private disk; card images and rich-text attachments go on the public disk.
    function designdir($storageKey, string $sub): string
    {
        return 'designs/' . ($storageKey ?: 'unassigned') . '/' . $sub;
    }

    function getfrqpath($storageKey, $position)
    {
        return $this->designdir($storageKey, str($position ?: 'Unsorted')->upper()->toString() . '/Frequency');
    }

    function getzpath($storageKey, $position)
    {
        return $this->designdir($storageKey, str($position ?: 'Unsorted')->upper()->toString() . '/Impedance');
    }

    function getotherpath($storageKey, $position)
    {
        return $this->designdir($storageKey, str($position ?: 'Unsorted')->upper()->toString() . '/Other');
    }

    function getenclosurepath($storageKey)
    {
        return $this->designdir($storageKey, 'Enclosure');
    }

    function getelectronicspath($storageKey)
    {
        return $this->designdir($storageKey, 'Electronics');
    }

    function getdesignotherpath($storageKey)
    {
        return $this->designdir($storageKey, 'Other Files');
    }

    function getwidgetresponsepath($storageKey)
    {
        return $this->designdir($storageKey, 'Attachments/Display Responses');
    }

    function getphotospath($storageKey)
    {
        return $this->designdir($storageKey, 'Attachments/Display Images');
    }

    function getsummaryattachmentspath($storageKey)
    {
        return $this->designdir($storageKey, 'Attachments/Summary Images');
    }

    function getdescriptionattachmentspath($storageKey)
    {
        return $this->designdir($storageKey, 'Attachments/Description Images');
    }

    /**
     * A paid design can only be published once it can actually be sold (snapshot + payout account).
     * Otherwise it is saved as a draft and the reasons are shown.
     */
    function guardPublish(array $data, ?Design $design = null): array
    {
        if (empty($data['active'])) {
            return $data;
        }

        $probe = $design ? clone $design : new Design(['user_id' => auth()->id()]);
        $probe->price_cents = $data['price_cents'] ?? $probe->price_cents ?? 0;

        if ($blockers = $probe->publishBlockers()) {
            $data['active'] = false;
            Notification::make()
                ->warning()
                ->title('Saved as a draft')
                ->body(implode(' ', $blockers))
                ->send();
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Design::query()->where('user_id', auth()->id()))
            ->heading('Designs')
            ->description('Manage your Designs here!')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();
                        $data['card_image'] = $data['card_image'] ?? '/demo/Design_Placeholder.JPG';
                        if (auth()->user()->hasRole('manufacturer')) {
                            $data['official'] = 1;
                        };
                        return $this->guardPublish($data);
                    })
                    ->form([
                        Toggle::make('active')
                            ->label('Published')
                            ->onColor('success'),

                        Hidden::make('storage_key')
                            ->default(fn () => (string) Str::uuid()),

                        Section::make('Design Information')
                            ->description('Basic information about the design')
                            ->collapsible()
                            ->schema([
                                TextInput::make('name')
                                    ->hint('The name of your design')
                                    ->required()
                                    ->maxLength(255),

                                FileUpload::make('card_image')
                                    ->multiple()
                                    ->label('Design Images')
                                    ->hint('Images that will be displayed publicly')
                                    ->disk('public')
                                    ->directory(function ($get) {
                                        $storageKey = $get('storage_key');
                                        return $this->getphotospath($storageKey);
                                    })
                                    ->visibility('public')
                                    ->required(),


                                FileUpload::make('frd_files')
                                    ->label('Response Previews')
                                    ->hint('These are representative of your designs. Upload multiple component responses or a single response')
                                    ->multiple()
                                    ->preserveFilenames()
                                    ->disk('private')
                                    ->visibility('private')
                                    ->directory(function ($get) {
                                        $storageKey = $get('storage_key');
                                        return $this->getwidgetresponsepath($storageKey);
                                    }),

                                TextInput::make('tag')
                                    ->label('Tagline')
                                    ->hint('A short descriptor for your design')
                                    ->maxLength(255)
                                    ->placeholder($this->speakerTaglines[array_rand($this->speakerTaglines)]),

                                Select::make('category')
                                    ->required()
                                    ->hint('What type of speaker is your design?')
                                    ->options(collect(DesignCategoryEnum::cases())->mapWithKeys(fn ($case) => [
                                        $case->name => $case->value,
                                    ])->toArray()),
                                MoneyInput::make('price_cents')
                                    ->label('Price')
                                    ->hint('The selling price of your design. Leave at 0 to share it for free.')
                                    ->helperText('Paid designs need a snapshot and a connected payout account before they can be published.')
                                    ->default(0),

                                MoneyInput::make('build_cost_cents')
                                    ->label('Build cost')
                                    ->hint('The cost to build a single speaker'),

                                TextInput::make('impedance')
                                    ->hint('The nominal impedance of the design')
                                    ->numeric(),

                                TextInput::make('power')
                                    ->hint('The power rating of the design')
                                    ->numeric(),

                                TiptapEditor::make('summary')
                                    ->profile('default')
                                    ->hint('The selling point of your design')
                                    ->disk('public')
                                    ->directory(fn ($get) => $this->getsummaryattachmentspath($get('storage_key')))
                                    ->output(TiptapOutput::Html)
                                    ->extraInputAttributes(['style' => 'min-height: 12rem;']),

                                Section::make('Private Information')
                                    ->collapsible()
                                    ->schema([
                                        TipTapEditor::make('description')
                                            ->hint('The main information area of your design, describe the design in greater detail')
                                            ->disk('public')
                                            ->directory(fn ($get) => $this->getdescriptionattachmentspath($get('storage_key')))
                                            ->extraInputAttributes(['style' => 'min-height: 12rem;']),
                                        KeyValue::make('bill_of_materials')
                                            ->hint('The BOM of your design'),
                                        Section::make('Design File Uploads')
                                            ->description('Upload your design files here')
                                            ->collapsible()
                                            ->collapsed()
                                            ->schema([
                                                FileUpload::make('enclosure_files')
                                                    ->hint('These are files related to the enclosure design')
                                                    ->helperText('Drawings, CAD files, Blueprints, etc...')
                                                    ->label('Enclosure Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getenclosurepath($storageKey);
                                                    }),

                                                FileUpload::make('electronic_files')
                                                    ->hint('These are files related to the electronics')
                                                    ->helperText('Crossover Schematics, PCB Designs, etc...')
                                                    ->label('Electronics Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getelectronicspath($storageKey);
                                                    }),

                                                FileUpload::make('design_other_files')
                                                    ->hint('These are other files related to the design')
                                                    ->helperText('Images, Recordings, Writeups, etc...')
                                                    ->label('Other Design Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getdesignotherpath($storageKey);
                                                    }),
                                            ]),]),]),
                        Section::make('Design Drivers')
                            ->collapsed()
                            ->description('Attach and upload data for the drivers in your design')
                            ->schema([
                                Repeater::make('components')
                                    ->label('')
                                    ->addActionLabel('Add Driver To Design')
                                    ->collapsed()
                                    ->defaultItems(0)
                                    ->collapsible()
                                    ->relationship()
                                    ->itemLabel(fn(array $state): ?string => $state['position'] ?? 'New Component')
                                    ->schema([

                                        Select::make('driver_id')
                                            ->hint('attach a driver to this band. If the driver you used is not available please go create an entry in My Drivers')
                                            ->searchable(['brand', 'model'])
                                            ->searchPrompt('Search by Brand or Model')
                                            ->options(Driver::where('active', 1)->select('id', 'brand', 'model', 'size', 'category')->get()->mapWithKeys(function ($driver) {
                                                return [$driver->id => $driver->brand . ' ' . $driver->model . ': ' . $driver->size . ' inch ' . $driver->category];
                                            }))
                                            ->preload()
                                            ->nullable(false)
                                            ->live()
                                            ->native(false)
                                            ->label('Driver'),

                                        Select::make('position')
                                            ->hint('Which frequency band this driver occupies in the design')
                                            ->live()
                                            ->options(['LF' => 'LF', 'LMF' => 'LMF', 'MF' => 'MF', 'HMF' => 'HMF', 'HF' => 'HF', 'Other' => 'Other']),

                                        TextInput::make('quantity')
                                            ->hint('The number of drivers in this band')
                                            ->numeric(),

                                        Section::make('Frequency Range')
                                            ->description('The bandwidth the driver occupies')
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('low_frequency')
                                                    ->columns(1)
                                                    ->numeric(),

                                                TextInput::make('high_frequency')
                                                    ->columns(1)
                                                    ->numeric(),
                                            ]),

                                        TextInput::make('air_volume')
                                            ->hint('The enclosed air volume of the driver')
                                            ->default(0)
                                            ->numeric(),

                                        Section::make('Driver File Uploads')
                                            ->description('Upload the working files used to design your speaker')
                                            ->collapsed()
                                            ->collapsible()
                                            ->schema([
                                                FileUpload::make('frequency_files')
                                                    ->label('Frequency Measurements')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getfrqpath($storageKey, $position);
                                                    }),

                                                FileUpload::make('impedance_files')
                                                    ->label('Impedance Measurements')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getzpath($storageKey, $position);
                                                    }),

                                                FileUpload::make('other_files')
                                                    ->label('Other Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getotherpath($storageKey, $position);
                                                    }),]),
                                        TiptapEditor::make('description')
                                            ->hint('This area allows you to describe the design driver in more detail.')
                                            ->disk('public')
                                            ->directory(fn ($get) => $this->getdescriptionattachmentspath($get('../../storage_key')))
                                            ->extraInputAttributes(['style' => 'min-height: 12rem;']),

                                        KeyValue::make('specifications')
                                            ->hint('These measurements are taken by the designer during testing, not copied from factory specs. All fields optional.')
                                            ->default([
                                                'Re' => '',
                                                'Fs' => '',
                                                'Qms' => '',
                                                'Qes' => '',
                                                'Qts' => '',
                                                'Rms' => '',
                                                'Mms' => '',
                                                'Cms' => '',
                                                'Vas' => '',
                                                'Sd' => '',
                                                'BL' => '',
                                                'Xmax' => '',
                                                'Le' => '',
                                                'SPL' => '',
                                                'EBP' => '',
                                                'Vd' => '',
                                                'Mmd' => '',
                                            ])
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->editableKeys(false)
                                            ->keyLabel('Parameter')
                                            ->valueLabel('Value')
                                    ])

                            ])])

            ])
            ->columns([

                ToggleColumn::make('active')
                    ->onColor('success')
                    ->updateStateUsing(function (Design $record, $state) {
                        $data = $this->guardPublish(['active' => (bool) $state, 'price_cents' => $record->price_cents], $record);
                        $record->update(['active' => $data['active']]);

                        return $data['active'];
                    }),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tag')
                    ->limit(50)
                    ->searchable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('View')
                    ->icon('phosphor-eye')
                    ->url(fn($record): string => route('design', ['id' => $record->id])),
                Action::make('createDesignSnapshot')
                    ->label('New Snapshot')
                    ->icon('heroicon-m-camera')
                    ->color('primary')
                    ->form([
                        TextInput::make('version')
                            ->label('Snapshot Version')
                            ->default('v' . now()->format('YmdHis'))
                            ->required()
                            ->placeholder('Enter version (e.g., v1.0)')
                    ])
                    ->action(function (array $data, ?Design $record) {
                        BuildDesignSnapshot::dispatch($record, $data['version'], auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Snapshot queued')
                            ->body('Building ' . $data['version'] . '. You will get a notification when it is ready.')
                            ->send();
                    }),
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data, Design $record): array => $this->guardPublish($data, $record))
                    ->form([
                        Toggle::make('active')
                            ->label('Published')
                            ->onColor('success'),

                        Hidden::make('storage_key')
                            ->default(fn () => (string) Str::uuid()),

                        Section::make('Design Information')
                            ->description('Basic information about the design')
                            ->collapsible()
                            ->schema([
                                TextInput::make('name')
                                    ->hint('The name of your design')
                                    ->required()
                                    ->maxLength(255),

                                FileUpload::make('card_image')
                                    ->multiple()
                                    ->label('Design Images')
                                    ->hint('Images that will be displayed publicly')
                                    ->disk('public')
                                    ->directory(function ($get) {
                                        $storageKey = $get('storage_key');
                                        return $this->getphotospath($storageKey);
                                    })
                                    ->visibility('public')
                                    ->required(),


                                FileUpload::make('frd_files')
                                    ->label('Response Previews')
                                    ->hint('These are representative of your designs. Upload multiple component responses or a single response')
                                    ->multiple()
                                    ->preserveFilenames()
                                    ->disk('private')
                                    ->visibility('private')
                                    ->directory(function ($get) {
                                        $storageKey = $get('storage_key');
                                        return $this->getwidgetresponsepath($storageKey);
                                    }),

                                TextInput::make('tag')
                                    ->label('Tagline')
                                    ->hint('A short descriptor for your design')
                                    ->maxLength(255)
                                    ->placeholder($this->speakerTaglines[array_rand($this->speakerTaglines)]),

                                Select::make('category')
                                    ->required()
                                    ->hint('What type of speaker is your design?')
                                    ->options(collect(DesignCategoryEnum::cases())->mapWithKeys(fn ($case) => [
                                        $case->name => $case->value,
                                    ])->toArray()),
                                MoneyInput::make('price_cents')
                                    ->label('Price')
                                    ->hint('The selling price of your design. Leave at 0 to share it for free.')
                                    ->helperText('Paid designs need a snapshot and a connected payout account before they can be published.')
                                    ->default(0),

                                MoneyInput::make('build_cost_cents')
                                    ->label('Build cost')
                                    ->hint('The cost to build a single speaker'),

                                TextInput::make('impedance')
                                    ->hint('The nominal impedance of the design')
                                    ->numeric(),

                                TextInput::make('power')
                                    ->hint('The power rating of the design')
                                    ->numeric(),

                                TiptapEditor::make('summary')
                                    ->hint('The selling point of your design')
                                    ->disk('public')
                                    ->directory(fn ($get) => $this->getsummaryattachmentspath($get('storage_key')))
                                    ->extraInputAttributes(['style' => 'min-height: 12rem;']),

                                Section::make('Private Information')
                                    ->collapsible()
                                    ->schema([
                                        TipTapEditor::make('description')
                                            ->hint('The main information area of your design, describe the design in greater detail')
                                            ->disk('public')
                                            ->directory(fn ($get) => $this->getdescriptionattachmentspath($get('storage_key')))
                                            ->extraInputAttributes(['style' => 'min-height: 12rem;']),
                                        KeyValue::make('bill_of_materials')
                                            ->hint('The BOM of your design')
                                            ->live(),
                                        Section::make('Design File Uploads')
                                            ->description('Upload your design files here')
                                            ->collapsible()
                                            ->collapsed()
                                            ->schema([
                                                FileUpload::make('enclosure_files')
                                                    ->hint('These are files related to the enclosure design')
                                                    ->helperText('Drawings, CAD files, Blueprints, etc...')
                                                    ->label('Enclosure Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getenclosurepath($storageKey);
                                                    }),

                                                FileUpload::make('electronic_files')
                                                    ->hint('These are files related to the electronics')
                                                    ->helperText('Crossover Schematics, PCB Designs, etc...')
                                                    ->label('Electronics Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getelectronicspath($storageKey);
                                                    }),

                                                FileUpload::make('design_other_files')
                                                    ->hint('These are other files related to the design')
                                                    ->helperText('Images, Recordings, Writeups, etc...')
                                                    ->label('Other Design Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('storage_key');
                                                        return $this->getdesignotherpath($storageKey);
                                                    }),
                                            ]),]),]),
                        Section::make('Design Drivers')
                            ->collapsed()
                            ->description('Attach and upload data for the drivers in your design')
                            ->schema([
                                Repeater::make('components')
                                    ->label('')
                                    ->addActionLabel('Add Driver To Design')
                                    ->collapsed()
                                    ->defaultItems(0)
                                    ->collapsible()
                                    ->relationship()
                                    ->itemLabel(fn(array $state): ?string => $state['position'] ?? 'New Component')
                                    ->schema([

                                        Select::make('driver_id')
                                            ->hint('attach a driver to this band. If the driver you used is not available please go create an entry in My Drivers')
                                            ->searchable(['brand', 'model'])
                                            ->searchPrompt('Search by Brand or Model')
                                            ->options(Driver::where('active', 1)->select('id', 'brand', 'model', 'size', 'category')->get()->mapWithKeys(function ($driver) {
                                                return [$driver->id => $driver->brand . ' ' . $driver->model . ': ' . $driver->size . ' inch ' . $driver->category];
                                            }))
                                            ->preload()
                                            ->nullable(false)
                                            ->live()
                                            ->native(false)
                                            ->label('Driver'),

                                        Select::make('position')
                                            ->hint('Which frequency band this driver occupies in the design')
                                            ->live()
                                            ->options(['LF' => 'LF', 'LMF' => 'LMF', 'MF' => 'MF', 'HMF' => 'HMF', 'HF' => 'HF', 'Other' => 'Other']),

                                        TextInput::make('quantity')
                                            ->hint('The number of drivers in this band')
                                            ->numeric(),

                                        Section::make('Frequency Range')
                                            ->description('The bandwidth the driver occupies')
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('low_frequency')
                                                    ->columns(1)
                                                    ->numeric(),

                                                TextInput::make('high_frequency')
                                                    ->columns(1)
                                                    ->numeric(),
                                            ]),

                                        TextInput::make('air_volume')
                                            ->hint('The enclosed air volume of the driver')
                                            ->default(0)
                                            ->numeric(),

                                        Section::make('Driver File Uploads')
                                            ->description('Upload the working files used to design your speaker')
                                            ->collapsed()
                                            ->collapsible()
                                            ->schema([
                                                FileUpload::make('frequency_files')
                                                    ->label('Frequency Measurements')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getfrqpath($storageKey, $position);
                                                    }),

                                                FileUpload::make('impedance_files')
                                                    ->label('Impedance Measurements')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getzpath($storageKey, $position);
                                                    }),

                                                FileUpload::make('other_files')
                                                    ->label('Other Files')
                                                    ->multiple()
                                                    ->preserveFilenames()
                                                    ->disk('private')
                                                    ->visibility('private')
                                                    ->directory(function ($get) {
                                                        $storageKey = $get('../../storage_key');
                                                        $position = $get('position');
                                                        return $this->getotherpath($storageKey, $position);
                                                    }),]),
                                        TiptapEditor::make('description')
                                            ->hint('This area allows you to describe the design driver in more detail.')
                                            ->disk('public')
                                            ->directory(fn ($get) => $this->getdescriptionattachmentspath($get('../../storage_key')))
                                            ->extraInputAttributes(['style' => 'min-height: 12rem;']),

                                        KeyValue::make('specifications')
                                            ->hint('These measurements are taken by the designer during testing, not copied from factory specs.')
                                            ->default([
                                                'Re' => '',
                                                'Fs' => '',
                                                'Qms' => '',
                                                'Qes' => '',
                                                'Qts' => '',
                                                'Rms' => '',
                                                'Mms' => '',
                                                'Cms' => '',
                                                'Vas' => '',
                                                'Sd' => '',
                                                'BL' => '',
                                                'Xmax' => '',
                                                'Le' => '',
                                                'SPL' => '',
                                                'EBP' => '',
                                                'Vd' => '',
                                                'Mmd' => '',
                                            ])
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->editableKeys(false)
                                            ->keyLabel('Parameter')
                                            ->valueLabel('Value')
                                    ])

                            ])]),
                DeleteAction::make()
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Project deleted')
                            ->send();
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_id'] = auth()->id();
                        return $data;
                    }),
            ])
            ->filters([
                // Add any filters you want here
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('active'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('tag')
                    ->maxLength(255),
                Select::make('category')
                    ->options(['Subwoofer' => 'Subwoofer', 'Full-Range' => 'Full-Range', 'Two-Way' => 'Two-Way'
                        , 'Three-Way' => 'Three-Way', 'Four-Way+' => 'Four-Way+', 'Portable' => 'Portable', 'Esoteric' => 'Esoteric']),
                MoneyInput::make('price_cents'),
                MoneyInput::make('build_cost_cents'),
                TextInput::make('impedance')
                    ->numeric(),
                TextInput::make('power')
                    ->numeric(),
                Textarea::make('summary')
                    ->columns(2),
                RichEditor::make('description'),
                KeyValue::make('bill_of_materials'),
            ])
            ->statePath('data');
    }

}
?>


<x-layouts.app>
    @volt('dashboard.designs')
<div>
    {{ $this->table }}
</div>
    @endvolt
</x-layouts.app>

