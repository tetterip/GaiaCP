{{-- The New hosting / New service window over the services list (ListServices): the picker itself. --}}
@livewire(\App\Livewire\ProductPicker::class, ['kind' => $kind], key('product-picker-'.$kind))
