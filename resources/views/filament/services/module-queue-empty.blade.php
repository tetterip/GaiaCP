{{-- Services → Module queue with nothing open: good news, so a green tick instead of the table's grey X --}}
<x-filament::empty-state
    :contained="false"
    icon="heroicon-o-check-circle"
    icon-color="success"
    :heading="__('All good')"
    :description="__('No panel or registrar action is waiting to be retried.')"
/>
