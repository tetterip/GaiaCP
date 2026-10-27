<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domain pricing per TLD (Settings → Domain pricing) instead of per registrar: one row per TLD with its prices for
 * 1 to 3 years, late renewal fees and a preferred registrar. Each TLD a registrar sold moves here with that
 * registrar as its preferred one (the default registrar's prices win when two sold it). Also adds the built-in
 * "Email Notifications" registrar account (plugins/EmailRegistrar), for TLDs no integrated registrar handles.
 */
return new class extends Migration
{
    private const KINDS = ['register', 'transfer', 'renew'];

    public function up(): void
    {
        Schema::create('tlds', function (Blueprint $table) {
            $table->id();
            $table->string('tld', 63)->unique(); // with the leading dot, lowercase: ".com.gr"
            $table->foreignId('registrar_id')->nullable()->constrained()->nullOnDelete(); // preferred registrar
            $table->boolean('active')->default(true);
            $table->boolean('epp_required')->default(true);
            foreach (self::KINDS as $kind) {
                foreach ([1, 2, 3] as $years) {
                    $table->unsignedInteger("{$kind}_{$years}y_cents")->nullable();
                }
            }
            $table->unsignedSmallInteger('grace_days')->nullable();
            $table->unsignedInteger('grace_fee_cents')->nullable();
            $table->unsignedInteger('redemption_fee_cents')->nullable();
            $table->timestamps();
        });

        $rows = DB::table('registrar_tlds')
            ->join('registrars', 'registrars.id', '=', 'registrar_tlds.registrar_id')
            ->orderByDesc('registrars.is_default')
            ->orderByDesc('registrars.active')
            ->orderByDesc('registrar_tlds.active')
            ->orderBy('registrar_tlds.id')
            ->select('registrar_tlds.*')
            ->get()
            ->unique('tld');

        foreach ($rows as $row) {
            DB::table('tlds')->insert([
                'tld' => $row->tld,
                'registrar_id' => $row->registrar_id,
                'active' => $row->active,
                'epp_required' => $row->epp_required,
                ...collect(self::KINDS)->flatMap(fn (string $kind) => [
                    "{$kind}_1y_cents" => $row->{"{$kind}_1y_cents"},
                    "{$kind}_2y_cents" => $row->{"{$kind}_2y_cents"},
                ])->all(),
                'grace_days' => $row->grace_days,
                'grace_fee_cents' => $row->grace_fee_cents,
                'redemption_fee_cents' => $row->redemption_fee_cents,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('registrar_tlds');

        if (! DB::table('registrars')->where('module', 'email')->exists()) {
            DB::table('registrars')->insert([
                'name' => 'Email Notifications',
                'module' => 'email',
                'credentials' => null,
                'verify_tls' => true,
                'active' => true,
                'is_default' => ! DB::table('registrars')->exists(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::create('registrar_tlds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registrar_id')->constrained()->cascadeOnDelete();
            $table->string('tld', 63);
            $table->boolean('active')->default(true);
            $table->boolean('epp_required')->default(true);
            foreach (self::KINDS as $kind) {
                $table->unsignedInteger("{$kind}_1y_cents")->nullable();
                $table->unsignedInteger("{$kind}_2y_cents")->nullable();
            }
            $table->unsignedSmallInteger('grace_days')->nullable();
            $table->unsignedInteger('grace_fee_cents')->nullable();
            $table->unsignedInteger('redemption_fee_cents')->nullable();
            $table->timestamps();

            $table->unique(['registrar_id', 'tld']);
        });

        foreach (DB::table('tlds')->whereNotNull('registrar_id')->get() as $row) {
            DB::table('registrar_tlds')->insert([
                'registrar_id' => $row->registrar_id,
                'tld' => $row->tld,
                'active' => $row->active,
                'epp_required' => $row->epp_required,
                ...collect(self::KINDS)->flatMap(fn (string $kind) => [
                    "{$kind}_1y_cents" => $row->{"{$kind}_1y_cents"},
                    "{$kind}_2y_cents" => $row->{"{$kind}_2y_cents"},
                ])->all(),
                'grace_days' => $row->grace_days,
                'grace_fee_cents' => $row->grace_fee_cents,
                'redemption_fee_cents' => $row->redemption_fee_cents,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('tlds');
    }
};
