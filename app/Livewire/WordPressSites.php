<?php

namespace App\Livewire;

use App\Actions\ServiceLifecycle;
use App\Models\Service;
use App\Provisioning\ProvisioningException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The WordPress sites of a client's hosting account (ServiceLifecycle::wordPressSites(), kept a few minutes), each
 * with Log in to WordPress, on the client's hosting page. Loaded after the page (lazy); Install WordPress reads it again.
 */
class WordPressSites extends Component
{
    #[Locked]
    public Service $service;

    /** Set after an install for this request only. */
    protected bool $readNow = false;

    public function placeholder(): string
    {
        return '<div style="padding: .25rem 0; font-size: .875rem; color: var(--gray-500)">'.e(__('Looking for WordPress sites…')).'</div>';
    }

    #[On('wordpress-installed')]
    public function readAgain(): void
    {
        $this->readNow = true;
    }

    public function render(): View
    {
        $sites = null;

        try {
            $sites = app(ServiceLifecycle::class)->wordPressSites($this->service, $this->readNow);
        } catch (ProvisioningException) {
            // WP Toolkit missing on the server, or the panel can't be reached
        }

        return view('livewire.wordpress-sites', ['sites' => $sites]);
    }
}
