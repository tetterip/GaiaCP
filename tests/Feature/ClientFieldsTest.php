<?php

namespace Tests\Feature;

use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Auth\EditProfile;
use App\Filament\Client\Pages\Auth\Register;
use App\Filament\Client\Resources\PaymentNotices\Pages\ViewPaymentNotice;
use App\Filament\Resources\ClientFields\Pages\ManageClientFields;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Client;
use App\Models\ClientField;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ClientFields;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Client fields: the admin's own fields on the client form, sign up, the client's profile and payment notices. */
class ClientFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $key, array $attributes = []): ClientField
    {
        $field = ClientField::create($attributes + [
            'key' => $key,
            'labels' => ['en' => ucfirst(str_replace('_', ' ', $key)), 'el' => 'Ελληνικά '.$key],
            'type' => ClientFieldType::Text,
            'client_access' => ClientFieldAccess::Editable,
        ]);
        ClientFields::flush();

        return $field;
    }

    public function test_an_admin_adds_a_field_and_the_key_comes_from_the_label(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageClientFields::class)
            ->callAction('create', data: [
                'labels' => ['en' => 'ID card number', 'el' => 'Αριθμός ταυτότητας'],
                'type' => ClientFieldType::Text->value,
                'options' => ['ignored'],
                'client_access' => ClientFieldAccess::Shown->value,
                'on_notice' => true,
            ])
            ->assertHasNoActionErrors();

        $field = ClientField::sole();
        $this->assertSame('id_card_number', $field->key);
        $this->assertNull($field->options); // only a list of choices keeps them
        $this->assertSame('Αριθμός ταυτότητας', $field->label('el'));
        $this->assertTrue($field->on_notice);

        Livewire::test(ManageClientFields::class)
            ->callAction('create', data: ['labels' => ['en' => 'Size'], 'key' => 'Bad key', 'type' => ClientFieldType::Select->value, 'client_access' => 'editable'])
            ->assertHasActionErrors(['key' => 'regex', 'options' => 'required']);
    }

    public function test_the_client_form_saves_the_values_and_checks_required_ones(): void
    {
        $this->field('id_card', ['required' => true]);
        $this->field('size', ['type' => ClientFieldType::Select, 'options' => ['Small', 'Large']]);
        $this->field('newsletter', ['type' => ClientFieldType::Checkbox]);
        $client = Client::factory()->create(['custom_fields' => ['old_field' => 'kept']]);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditClient::class, ['record' => $client->id])
            ->assertSee('More details')
            ->fillForm(['custom_fields' => ['id_card' => '', 'size' => 'Huge']])
            ->call('save')
            ->assertHasFormErrors(['custom_fields.id_card' => 'required', 'custom_fields.size']);

        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['custom_fields' => ['id_card' => 'AK 123456', 'size' => 'Large', 'newsletter' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        $values = $client->refresh()->custom_fields;
        $this->assertSame('AK 123456', $values['id_card']);
        $this->assertSame('Large', $values['size']);
        $this->assertTrue($values['newsletter']);
        $this->assertSame('kept', $values['old_field']); // a deleted field's value stays
    }

    public function test_sign_up_saves_only_the_fields_clients_fill_in(): void
    {
        Mail::fake();
        config(['gaiacp.client_area.signup' => true]);
        $this->field('id_card', ['required' => true]);
        $this->field('vip', ['client_access' => ClientFieldAccess::Shown]);
        $this->field('notes', ['client_access' => ClientFieldAccess::Hidden]);
        Filament::setCurrentPanel('client');

        $details = [
            'first_name' => 'Maria', 'last_name' => 'Papa', 'email' => 'maria@example.gr', 'phone' => '+30 6912345678',
            'address1' => 'Ermou 1', 'city' => 'Athens', 'postcode' => '10563', 'country' => 'GR',
            'password' => 'Welcome2026x', 'passwordConfirmation' => 'Welcome2026x',
        ];

        Livewire::test(Register::class)
            ->assertSee('Id card')->assertDontSee('Vip')->assertDontSee('Notes')
            ->fillForm($details)
            ->call('register')
            ->assertHasFormErrors(['custom_fields.id_card' => 'required']);

        Livewire::test(Register::class)
            ->fillForm($details + ['custom_fields' => ['id_card' => ' AK 1 ', 'vip' => 'yes', 'notes' => 'sneaky']])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertSame(['id_card' => 'AK 1'], Client::sole()->custom_fields);
    }

    public function test_the_profile_saves_editable_fields_at_once_and_shows_the_others_read_only(): void
    {
        $this->field('id_card');
        $this->field('vip', ['client_access' => ClientFieldAccess::Shown]);
        $this->field('notes', ['client_access' => ClientFieldAccess::Hidden]);
        $client = Client::factory()->create(['custom_fields' => ['vip' => 'Gold', 'notes' => 'Pays late']]);
        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email, 'password' => 'secret-password']);
        Filament::setCurrentPanel('client');
        $this->actingAs($user, 'client');

        Livewire::test(EditProfile::class)
            ->assertSee('Id card')->assertSee('Vip')->assertDontSee('Pays late')
            ->assertFormFieldIsDisabled('custom_fields.vip')
            ->fillForm(['custom_fields' => ['id_card' => 'AK 9', 'vip' => 'Platinum']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['vip' => 'Gold', 'notes' => 'Pays late', 'id_card' => 'AK 9'], $client->refresh()->custom_fields);
        $this->assertSame(0, $client->changeRequests()->count());
    }

    public function test_payment_notices_show_the_chosen_fields_as_they_were_on_the_day(): void
    {
        $this->field('id_card', ['on_notice' => true]);
        $this->field('member', ['type' => ClientFieldType::Checkbox, 'on_notice' => true]);
        $this->field('notes', ['client_access' => ClientFieldAccess::Hidden]);
        $client = Client::factory()->create(['custom_fields' => ['id_card' => 'AK 1', 'member' => true, 'notes' => 'Pays late']]);

        $notice = Invoice::create([
            'number' => Invoice::nextNumber(now()), 'client_id' => $client->id, 'date' => now(), 'due_date' => now()->addDays(14),
            'currency' => 'EUR', 'subtotal_cents' => 1000, 'tax_cents' => 0, 'total_cents' => 1000,
        ]);

        $this->assertSame(['id_card' => 'AK 1', 'member' => __('Yes')], $notice->bill_to['custom']);
        $this->assertFalse($notice->billToChanged());

        $client->update(['custom_fields' => ['id_card' => 'AK 2', 'member' => true]]);
        $this->assertTrue($notice->fresh()->billToChanged());
        $this->assertSame(['Id card: AK 1', 'Member: Yes'], ClientFields::noticeLines($notice->fresh()->billTo()->custom));

        $this->actingAs(User::factory()->create());
        Livewire::test(ViewInvoice::class, ['record' => $notice->id])->assertSee('Id card: AK 1')->assertDontSee('Pays late');

        $user = User::factory()->create(['role' => UserRole::Client, 'client_id' => $client->id, 'email' => $client->email]);
        $this->actingAs($user, 'client')->get(ViewPaymentNotice::getUrl(['record' => $notice->getRouteKey()], panel: 'client'))->assertOk()->assertSee('Id card: AK 1');
    }
}
