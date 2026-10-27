<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Ai\DataQuestions;
use App\Enums\AdminArea;
use App\Filament\Clusters\Reports\ReportCard;
use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Concerns\LimitedToAdminArea;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\Currencies;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Reports → Ask in plain words (Settings → AI assistant → Questions about your data): the admin types a question, the
 * AI assistant reads which of DataQuestions' questions it is (only the question and the list are sent), and GaiaCP
 * answers it from its own data, read-only, without the AI service. "Read as" shows how it was read, so the admin can
 * tell a wrong reading. Each question needs the admin's access to its area.
 */
class AskData extends Page implements ReportCard
{
    use LimitedToAdminArea {
        canAccess as private canAccessArea;
    }
    use TranslatesPageLabels;

    protected static ?string $cluster = ReportsCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Questions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Ask in plain words';

    protected static ?string $slug = 'ask';

    protected string $view = 'filament.reports.ask';

    public string $question = '';

    /** @var array{title: string, columns: array<string, string>, rows: list<array<string, mixed>>, total: ?string, more: bool}|null */
    public ?array $answer = null;

    public ?string $reply = null;

    public static function adminArea(): ?AdminArea
    {
        return AdminArea::Reports;
    }

    public static function canAccess(): bool
    {
        return Assistant::enabled('questions') && self::canAccessArea() && DataQuestions::allowed(auth()->user()) !== [];
    }

    public static function reportDescription(): string
    {
        return __('Ask a question such as "who owes more than €100?" and get the answer from your data.');
    }

    /** @return list<string> example questions, as buttons under the box */
    public function examples(): array
    {
        return [
            __('Who owes more than €100?'),
            __('How much did we receive last month?'),
            __('Which domains expire in the next 30 days?'),
            __('Which hosting accounts renew this month?'),
        ];
    }

    public function ask(?string $question = null): void
    {
        $this->resetErrorBag();
        $this->answer = null;
        $this->reply = null;
        $this->question = trim($question ?? $this->question);

        if ($this->question === '') {
            throw ValidationException::withMessages(['question' => __('Type a question first.')]);
        }

        $user = auth()->user();

        try {
            $read = app(Assistant::class)->readQuestion($this->question, DataQuestions::allowed($user), Currencies::base());

            if ($read['question'] === null) {
                $this->reply = $read['reply'] ?? __('The assistant can\'t answer that from your data yet. Try one of the examples.');

                return;
            }

            $this->answer = app(DataQuestions::class)->run($read['question'], $read['params'], $user);
        } catch (AiException $e) {
            throw ValidationException::withMessages(['question' => $e->getMessage()]);
        }
    }
}
