<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The public area's support (App\Support\PublicArea): a knowledge base article may stay for clients only, and a
    // ticket from the contact form has a secret link for its sender (tickets from email senders keep answering by email).
    // The contact form's captcha starts on where the forms were already chosen (Settings → General → Captcha).
    public function up(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->boolean('clients_only')->default(false)->after('published');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique()->after('name');
        });

        $forms = DB::table('settings')->where('key', 'captcha.forms')->value('value');
        $list = $forms === null ? null : json_decode($forms, true);
        if (is_array($list) && ! in_array('contact_form', $list, true)) {
            DB::table('settings')->where('key', 'captcha.forms')->update(['value' => json_encode([...$list, 'contact_form'])]);
        }
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });

        Schema::table('kb_articles', function (Blueprint $table) {
            $table->dropColumn('clients_only');
        });
    }
};
