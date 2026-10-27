{{-- App\Filament\Clusters\Reports\Pages\AskData: a question box, example questions, then how it was read and the answer from GaiaCP's data. --}}
<x-filament-panels::page>
    {{-- No custom theme is compiled, so the page carries its own CSS on Filament's color variables. --}}
    <style>
        .gcp-ask { display: flex; gap: .75rem; align-items: start; flex-wrap: wrap; }
        .gcp-ask > :first-child { flex: 1 1 20rem; }
        .gcp-ask-examples { display: flex; gap: .5rem; overflow-x: auto; scrollbar-width: none; }
        .gcp-ask-examples > * { flex: none; }
        .gcp-ask-error { font-size: .875rem; color: var(--danger-600); }
        .gcp-ask-note { font-size: .8125rem; color: var(--gray-500); }
        .gcp-ask-card { border-radius: .75rem; border: 1px solid var(--gray-200); overflow-x: auto; }
        .gcp-ask-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .gcp-ask-table th { padding: .6rem .75rem; text-align: start; font-weight: 600; color: var(--gray-950); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
        .gcp-ask-table td { padding: .5rem .75rem; border-bottom: 1px solid var(--gray-100); color: var(--gray-700); white-space: nowrap; }
        .gcp-ask-table a { color: var(--primary-600); }
        .gcp-ask-table tfoot td { font-weight: 600; color: var(--gray-950); border-bottom: 0; border-top: 1px solid var(--gray-200); }
        .dark .gcp-ask-card { border-color: rgb(255 255 255 / .1); }
        .dark .gcp-ask-table th { color: #fff; background: rgb(255 255 255 / .03); border-color: rgb(255 255 255 / .1); }
        .dark .gcp-ask-table td { color: var(--gray-300); border-color: rgb(255 255 255 / .05); }
        .dark .gcp-ask-table a { color: var(--primary-400); }
        .dark .gcp-ask-table tfoot td { color: #fff; border-color: rgb(255 255 255 / .1); }
        .dark .gcp-ask-error { color: var(--danger-400); }
    </style>

    <form wire:submit="ask()" class="gcp-ask">
        <x-filament::input.wrapper prefix-icon="heroicon-o-sparkles" :valid="! $errors->has('question')">
            <x-filament::input type="text" wire:model="question" maxlength="{{ \App\Ai\Assistant::MAX_QUESTION }}" :placeholder="__('e.g. Who owes more than €100?')" autocomplete="off" autofocus />
        </x-filament::input.wrapper>
        <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:target="ask">{{ __('Ask') }}</x-filament::button>
    </form>
    @error('question')
        <p class="gcp-ask-error">{{ $message }}</p>
    @enderror

    <div class="gcp-ask-examples">
        @foreach ($this->examples() as $example)
            <x-filament::button color="gray" size="xs" wire:click="ask(@js($example))" wire:key="example-{{ $loop->index }}">{{ $example }}</x-filament::button>
        @endforeach
    </div>

    <div wire:loading.class="opacity-50" wire:target="ask">
        @if ($reply)
            <x-filament::section>
                <p>{{ $reply }}</p>
            </x-filament::section>
        @elseif ($answer)
            <x-filament::section :heading="__('Read as: :question', ['question' => $answer['title']])">
                @if ($answer['rows'] === [])
                    <p class="gcp-ask-note">{{ __('Nothing found.') }}</p>
                @else
                    <div class="gcp-ask-card">
                        <table class="gcp-ask-table">
                            <thead>
                                <tr>
                                    @foreach ($answer['columns'] as $label)
                                        <th>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($answer['rows'] as $row)
                                    <tr wire:key="row-{{ $loop->index }}">
                                        @foreach (array_keys($answer['columns']) as $column)
                                            <td>
                                                @if ($loop->first && filled($row['url'] ?? null))
                                                    <a href="{{ $row['url'] }}">{{ $row[$column] ?? '' }}</a>
                                                @else
                                                    {{ $row[$column] ?? '' }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($answer['total'] !== null)
                                <tfoot>
                                    <tr>
                                        <td colspan="{{ count($answer['columns']) }}">{{ __('Total: :total', ['total' => $answer['total']]) }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                    @if ($answer['more'])
                        <p class="gcp-ask-note">{{ __('The first :count are shown.', ['count' => \App\Ai\DataQuestions::MAX_ROWS]) }}</p>
                    @endif
                @endif
                <p class="gcp-ask-note" style="margin-top: .75rem">{{ __('Answered by GaiaCP from your data. The AI service only read the question, never the answer.') }}</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
