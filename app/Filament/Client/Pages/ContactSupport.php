<?php

namespace App\Filament\Client\Pages;

use App\Actions\Tickets;
use App\Filament\Client\Concerns\OpenToVisitors;
use App\Filament\Client\Resources\Tickets\TicketResource;
use App\Filament\Concerns\HasCaptcha;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\TicketDepartment;
use App\Support\KnowledgeBase;
use App\Support\PublicArea;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Contact us (Settings → Public area → Contact page). The contact form: a visitor without a login opens a support ticket
 * with their name and email address. The captcha (Settings → General) and PER_HOUR from one address keep robots out; the ticket has no
 * client (Tickets::openFromVisitor()) and its emails carry its secret link (VisitorTicket), where it opens next.
 * Knowledge base articles matching the subject are offered first. Clients who are logged in open a ticket as usual.
 * The company's details (PublicArea::contactDetails(): the lines picked from Settings → Company, opening hours, a text and
 * an Open in maps button) show beside the form, also without it, and to clients in their Support menu.
 */
class ContactSupport extends Page
{
    use HasCaptcha;
    use OpenToVisitors;
    use TranslatesPageLabels;

    protected static ?string $title = 'Contact us';

    protected static ?string $slug = 'contact';

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 95;

    protected Width|string|null $maxContentWidth = Width::FourExtraLarge;

    /** Tickets one IP address may open in an hour, and for one email address. */
    public const PER_HOUR = 3;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return ! self::visitor() || PublicArea::contact() || PublicArea::contactDetails();
    }

    /** In a client's Support menu while the details are on (visitors get it from PublicArea::navigation()). */
    public static function shouldRegisterNavigation(): bool
    {
        return ! self::visitor() && PublicArea::contactDetails();
    }

    public function mount(): void
    {
        if (! self::visitor() && ! PublicArea::contactDetails()) {
            $this->redirect(TicketResource::canAccess() ? TicketResource::getUrl('create') : Dashboard::getUrl());

            return;
        }

        $this->form->fill();
    }

    /** Whether the form shows: to visitors, while the contact form is on. */
    public function withForm(): bool
    {
        return self::visitor() && PublicArea::contact();
    }

    public function getSubheading(): ?string
    {
        return $this->withForm() ? __('Write to us and we answer by email. You also get a link to see the conversation and reply to it.') : null;
    }

    public function content(Schema $schema): Schema
    {
        $details = PublicArea::contactDetails();
        $form = $this->withForm();

        return $schema->components([
            View::make('filament.client.contact-text')
                ->visible($details && PublicArea::contactText() !== null),
            // The details first: left of the form, above it on a phone
            Grid::make(['default' => 1, 'lg' => $form && $details ? 3 : 1])
                ->schema([
                    View::make('filament.client.contact-details')
                        ->viewData([
                            'ticketUrl' => ! self::visitor() && TicketResource::canAccess() ? TicketResource::getUrl('create') : null,
                        ])
                        ->visible($details),
                    Form::make([EmbeddedSchema::make('form')])
                        ->id('form')
                        ->livewireSubmitHandler('send')
                        ->footer([
                            Actions::make([Action::make('send')->label(__('Send'))->submit('send')]),
                        ])
                        ->visible($form)
                        ->columnSpan(['default' => 1, 'lg' => $details ? 2 : 1]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $departments = fn () => TicketDepartment::query()->clientVisible()->ordered()->pluck('name', 'id');

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Your name')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(190),
                        Select::make('department_id')
                            ->label('Department')
                            ->options($departments)
                            ->required(fn () => $departments()->isNotEmpty())
                            ->visible(fn () => $departments()->isNotEmpty())
                            ->columnSpanFull(),
                        TextInput::make('subject')
                            ->required()
                            ->maxLength(200)
                            ->live(debounce: 700)
                            ->columnSpanFull(),
                        // Knowledge base articles (those visitors see) that may already answer it
                        View::make('filament.client.kb-suggestions')
                            ->viewData(fn (Get $get) => ['articles' => KnowledgeBase::suggestions($get('subject'))])
                            ->visible(fn (Get $get) => KnowledgeBase::suggestions($get('subject'))->isNotEmpty())
                            ->columnSpanFull(),
                        Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->rows(8)
                            ->maxLength(20000)
                            ->columnSpanFull(),
                        // Left empty by people; a robot filling every field gives itself away
                        TextInput::make('website')
                            ->hiddenLabel()
                            ->extraAttributes(['class' => 'gcp-hp', 'aria-hidden' => 'true'])
                            ->extraInputAttributes(['tabindex' => '-1', 'autocomplete' => 'off']),
                        ...$this->captchaComponents('contact_form'),
                        Text::make(__('Already a client? Log in to open a ticket from your client area, where you also see your services.'))
                            ->color('gray')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function send(): void
    {
        abort_unless(self::visitor() && PublicArea::contact(), 404);

        $data = $this->withCaptcha(fn () => $this->form->getState());

        if (filled($data['website'] ?? null)) {
            return; // a robot: nothing is opened, nothing is said
        }

        // From one address, and to one address (each ticket emails it), so the form can't flood a mailbox either
        $to = 'contact-form-to:'.sha1(strtolower(trim((string) $data['email'])));
        if (RateLimiter::tooManyAttempts($to, self::PER_HOUR) || ! RateLimiter::attempt('contact-form:'.request()->ip(), self::PER_HOUR, fn () => true, 3600)) {
            throw ValidationException::withMessages(['data.body' => __('Too many messages from here. Please try again later.')]);
        }

        RateLimiter::hit($to, 3600);

        $ticket = app(Tickets::class)->openFromVisitor(
            $data['email'],
            $data['name'],
            $data['subject'],
            $data['body'],
            filled($data['department_id'] ?? null) ? TicketDepartment::query()->clientVisible()->find($data['department_id']) : null,
        );

        Notification::make()
            ->title(__('Your message was sent'))
            ->body(__('We emailed you a link to this page. We answer by email and here.'))
            ->success()
            ->send();

        $this->redirect((string) $ticket->publicUrl());
    }
}
