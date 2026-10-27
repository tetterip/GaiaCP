<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Ai\AiException;
use App\Ai\Assistant;
use App\Ai\ClientFacts;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The client page's Suggestions (Settings → AI assistant → Suggestions about clients): what the client might buy, signs
 * they may leave and what to look after, from ClientFacts and the products on offer. Read only: nothing is saved or
 * sent to the client. For admins who also see billing, since it reads how the client pays.
 */
class ClientHintsAction
{
    public static function make(): Action
    {
        return Action::make('aiClientHints')
            ->label('Suggestions')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn () => Assistant::enabled('clients') && InvoiceResource::canAccess())
            ->modalHeading(__('Suggestions by the AI assistant'))
            ->modalDescription(fn () => __('From :service, which saw this client\'s services, prices, payment history and ticket subjects, never who they are. Check them before you act.', ['service' => Assistant::provider()?->aiLabel()]))
            ->fillForm(function (Client $record, Action $action) {
                $facts = new ClientFacts($record);

                try {
                    $hints = app(Assistant::class)->clientHints([...$facts->payments(), ...$facts->services()], ClientFacts::catalogue($record->currencyCode()), app()->getLocale());
                } catch (AiException $e) {
                    Notification::make()->danger()->title(__('The AI assistant gave no answer'))->body($e->getMessage())->send();
                    $action->cancel();

                    return [];
                }

                return ['hints' => $hints];
            })
            ->schema([
                Hidden::make('hints'),
                Text::make(fn (Get $get) => self::list((array) $get('hints'))),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /** @param  list<array{kind: string, text: string}>  $hints */
    private static function list(array $hints): HtmlString
    {
        if ($hints === []) {
            return new HtmlString(e(__('Nothing to suggest about this client now.')));
        }

        $labels = ['offer' => __('Offer'), 'risk' => __('Risk'), 'care' => __('Look after')];

        return new HtmlString('<ul class="space-y-2">'.collect($hints)->map(fn (array $hint) => '<li><strong>'.e($labels[$hint['kind']] ?? $labels['care']).':</strong> '.e($hint['text']).'</li>')->implode('').'</ul>');
    }
}
