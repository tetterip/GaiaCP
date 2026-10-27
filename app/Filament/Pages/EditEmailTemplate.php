<?php

namespace App\Filament\Pages;

use App\Enums\AdminArea;
use App\Enums\MailTemplate;
use App\Filament\Actions\AiTextActions;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Mail\ClientMail;
use App\Support\Locales;
use App\Support\MailTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/** One email template: on/off, subject and body, with a preview and a reset to the built-in text. */
class EditEmailTemplate extends Page
{
    use LimitedToAdminArea;

    protected static ?string $cluster = SettingsCluster::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'email-templates/{template}';

    public string $template;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::EmailSettings;
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'email-templates.edit';
    }

    public function mount(string $template): void
    {
        abort_unless(MailTemplate::tryFrom($template), 404);

        $this->template = $template;
        $this->form->fill(['locale' => Locales::default(), ...MailTemplates::get($this->mailTemplate(), Locales::default())]);
    }

    public function mailTemplate(): MailTemplate
    {
        return MailTemplate::from($this->template);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->mailTemplate()->label();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            SettingsCluster::getUrl() => __('Settings'),
            EmailTemplates::getUrl() => __('Email templates'),
            $this->mailTemplate()->label(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $t = $this->mailTemplate();

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->description($t->sentWhen())
                    ->schema([
                        Select::make('locale')
                            ->label('Language')
                            ->options(Locales::available())
                            ->selectablePlaceholder(false)
                            ->helperText(__('Clients get the text in their own language; one not written yet uses the default language\'s.'))
                            ->live()
                            ->afterStateUpdated(fn (?string $state) => $this->data = [...$this->data, ...Arr::only(MailTemplates::get($t, $state), ['subject', 'body'])])
                            ->visible(Locales::multiple()),
                        Toggle::make('enabled')->label('Send this email automatically'),
                        TextInput::make('subject')->label('Subject')->required()->maxLength(255),
                        AiTextActions::on(MarkdownEditor::make('body')
                            ->label('Body')
                            ->required()
                            ->helperText(__('Markdown or HTML. Placeholders are filled in when the email is sent.'))),
                        Text::make(new HtmlString(e(__('Placeholders:')).' '.collect($t->placeholders())
                            ->map(fn (string $about, string $name) => '<code>{$'.e($name).'}</code> '.e(__($about)))
                            ->join(' · '))),
                        Actions::make([
                            Action::make('preview')
                                ->icon(Heroicon::OutlinedEye)
                                ->color('gray')
                                ->modalHeading(fn () => __('Preview: :subject', ['subject' => $this->preview()['subject']]))
                                // sandbox: the body may be HTML, which must not run with this admin's rights
                                ->modalContent(fn () => new HtmlString('<iframe sandbox style="width:100%;height:70vh;border:0;background:#fff" srcdoc="'
                                    .e($this->preview()['html']).'"></iframe>'))
                                ->modalSubmitAction(false)
                                ->modalCancelActionLabel(__('Close'))
                                ->modalWidth('4xl'),
                            Action::make('reset')
                                ->label(fn () => $this->hasBuiltIn() ? 'Reset to built-in text' : 'Reset to the default language\'s text')
                                ->icon(Heroicon::OutlinedArrowUturnLeft)
                                ->color('gray')
                                ->requiresConfirmation()
                                ->modalDescription(fn () => $this->hasBuiltIn()
                                    ? __('Replaces the subject and body with the built-in text. Nothing is saved until you press Save.')
                                    : __('Replaces the subject and body with the default language\'s text. Nothing is saved until you press Save.'))
                                ->action(fn () => $this->data = [...$this->data, ...$this->builtIn()]),
                        ])->key('templateActions'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save')->submit('save'),
                        Action::make('back')->label('Back to the list')->color('gray')->url(EmailTemplates::getUrl()),
                    ])->sticky()->alignEnd(),
                ]),
        ]);
    }

    /**
     * The email as it would go out with the text currently on the form, using a made-up client.
     *
     * @return array{subject: string, html: string}
     */
    public function preview(): array
    {
        $mail = ClientMail::sample($this->mailTemplate(), $this->locale());
        $mail->locale($this->locale());
        $mail->previewText = ['subject' => (string) ($this->data['subject'] ?? ''), 'body' => (string) ($this->data['body'] ?? '')];

        return ['subject' => $mail->envelope()->subject, 'html' => $mail->render()];
    }

    private function locale(): string
    {
        return Locales::normalize($this->data['locale'] ?? null);
    }

    private function hasBuiltIn(): bool
    {
        return Locales::isDefault($this->locale()) || MailTemplate::hasBuiltIn($this->locale());
    }

    /**
     * What "Reset" puts back: the language's built-in text, or in another language without one the default language's.
     *
     * @return array{subject: string, body: string}
     */
    private function builtIn(): array
    {
        $t = $this->mailTemplate();

        return Locales::isDefault($this->locale())
            ? ['subject' => $t->defaultSubject($this->locale()), 'body' => $t->defaultBody($this->locale())]
            : MailTemplates::fallback($t, $this->locale());
    }

    public function save(): void
    {
        $data = $this->form->getState();

        MailTemplates::save($this->mailTemplate(), $data['subject'] ?? null, $data['body'] ?? null, (bool) ($data['enabled'] ?? false), $this->locale());

        Notification::make()->success()->title(__('Email template saved'))->send();
    }
}
