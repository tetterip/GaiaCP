<?php

namespace Tests\Feature;

use App\Actions\PlaceOrder;
use App\Actions\RecordPayment;
use App\Enums\BillingCycle;
use App\Filament\Widgets\RecentActivity;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Support\ActivityText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\FakesWhm;
use Tests\TestCase;

class ActivityTextTest extends TestCase
{
    use FakesWhm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWhm();
        config(['gaiacp.region.date_format' => 'd/m/Y']);
    }

    public function test_a_known_description_is_shown_in_the_admins_language_with_the_regions_dates(): void
    {
        app()->setLocale('el');

        $this->assertSame('Καταχωρήθηκε πληρωμή 187,20 €· επόμενη λήξη 05/12/2026 → 05/12/2028',
            ActivityText::translate('payment.recorded', 'Payment of 187,20 € recorded; next due date 2026-12-05 → 2028-12-05'));
        $this->assertSame('Νέα παραγγελία #1 με 1 είδος', ActivityText::translate('order.placed', 'Order #1 placed with 1 item(s)'));
        $this->assertSame('Νέα παραγγελία #7 με 2 είδη και το κουπόνι SPRING', ActivityText::translate('order.placed', 'Order #7 placed with 2 item(s) and coupon SPRING'));
        $this->assertSame('Η παραγγελία #1 ολοκληρώθηκε', ActivityText::translate('order.completed', 'Order #1 completed'));
        $this->assertSame('Αίτημα ακύρωσης (Άμεσα), μαζί με το domain του: Too slow',
            ActivityText::translate('service.cancellation_requested', 'Cancellation requested (immediate), with its domain: Too slow'));
        $this->assertSame('Σε αναστολή: Ληξιπρόθεσμη πληρωμή', ActivityText::translate('service.suspended', 'Suspended: overdue'));
    }

    public function test_in_english_only_the_dates_change(): void
    {
        $this->assertSame('Payment of 187,20 € recorded; next due date 05/12/2026 → 05/12/2028',
            ActivityText::translate('payment.recorded', 'Payment of 187,20 € recorded; next due date 2026-12-05 → 2028-12-05'));
        $this->assertSame('Order #1 completed', ActivityText::translate('order.completed', 'Order #1 completed'));
        // A count written by older versions as "item(s)" reads right
        $this->assertSame('Order #1 placed with 1 item', ActivityText::translate('order.placed', 'Order #1 placed with 1 item(s)'));
        $this->assertSame('Order #2 placed with 3 items', ActivityText::translate('order.placed', 'Order #2 placed with 3 items'));
    }

    public function test_an_unknown_description_is_shown_as_stored_with_its_dates_in_the_regions_format(): void
    {
        app()->setLocale('el');

        $this->assertSame('Something new on 05/12/2026', ActivityText::translate('something.new', 'Something new on 2026-12-05'));
        // A description of a known event that the templates don't fit (written by an older version)
        $this->assertSame('Order 1 done', ActivityText::translate('order.completed', 'Order 1 done'));
    }

    public function test_what_the_code_records_matches_its_template(): void
    {
        app()->setLocale('el');
        $client = Client::factory()->create();
        $service = Service::factory()->for($client)->create([
            'start_date' => '2025-12-05',
            'next_due_date' => '2026-12-05',
            'billing_cycle' => BillingCycle::Annually,
        ]);

        app(RecordPayment::class)->handle($service, 18720, CarbonImmutable::parse('2026-11-27'), 'bank_transfer', 'REF-1');
        app(PlaceOrder::class)->handle($client, [['type' => 'other', 'label' => 'Setup', 'price_cents' => 1000, 'billing_cycle' => BillingCycle::Monthly]]);

        $payment = ActivityLog::where('event', 'payment.recorded')->sole();
        $this->assertSame("Καταχωρήθηκε πληρωμή 187,20\u{00A0}€· επόμενη λήξη 05/12/2026 → 05/12/2027", ActivityText::of($payment));
        $order = ActivityLog::where('event', 'order.placed')->sole();
        $this->assertMatchesRegularExpression('/^Νέα παραγγελία #\d+ με 1 είδος$/u', ActivityText::of($order));
    }

    public function test_the_recent_activity_card_and_the_activity_log_show_it_in_greek(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'el']));
        app()->setLocale('el');
        ActivityLog::record('order.completed', 'Order #1 completed', client: Client::factory()->create());

        Livewire::test(RecentActivity::class)->assertSee('Η παραγγελία #1 ολοκληρώθηκε')->assertDontSee('Order #1 completed');
        $this->get('/admin/activity-logs')->assertOk()->assertSee('Η παραγγελία #1 ολοκληρώθηκε');
    }

    public function test_every_template_has_its_placeholders_in_every_language(): void
    {
        foreach (File::glob(lang_path('*.json')) as $file) {
            $texts = json_decode(File::get($file), true);

            foreach (ActivityText::keys() as $template) {
                $this->assertArrayHasKey($template, $texts, basename($file));
                preg_match_all('/:[a-z_]+/', $template, $want);
                preg_match_all('/:[a-z_]+/', $texts[$template], $have);
                $this->assertEqualsCanonicalizing(array_unique($want[0]), array_unique($have[0]), basename($file).': '.$template);
            }
        }
    }
}
