<?php

use App\Service\System\PhoneLookup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $lookup = app(PhoneLookup::class);
        $fingerprint = $lookup->configuredFingerprint();
        Crypt::encryptString('migration-key-check');

        Schema::create('remit_phone_key', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('fingerprint', 64);
        });
        DB::table('remit_phone_key')->insert(['id' => 1, 'fingerprint' => $fingerprint]);

        Schema::table('users', function (Blueprint $table): void {
            $table->text('phone_cipher')->nullable();
            $table->string('phone_lookup', 64)->nullable()->unique();
        });
        Schema::table('sms_codes', function (Blueprint $table): void {
            $table->string('mobile_lookup', 64)->nullable();
            $table->unique(['mobile_lookup', 'scene']);
        });

        DB::table('users')->select('id', 'phone_e164')->whereNotNull('phone_e164')->chunkById(250, function ($rows) use ($lookup): void {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update([
                    'phone_cipher' => Crypt::encryptString($row->phone_e164), 'phone_lookup' => $lookup->digest($row->phone_e164),
                ]);
            }
        });
        DB::table('sms_codes')->select('id', 'mobile_e164')->chunkById(250, function ($rows) use ($lookup): void {
            foreach ($rows as $row) {
                DB::table('sms_codes')->where('id', $row->id)->update(['mobile_lookup' => $lookup->digest($row->mobile_e164)]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_e164']);
            $table->dropColumn('phone_e164');
        });
        Schema::table('sms_codes', function (Blueprint $table): void {
            $table->dropUnique(['mobile_e164', 'scene']);
            $table->dropColumn('mobile_e164');
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('phone_cipher')->exists() || DB::table('sms_codes')->exists()) {
            throw new RuntimeException('已保存受保护的手机号数据，禁止回退到明文结构。');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_lookup']);
            $table->dropColumn(['phone_cipher', 'phone_lookup']);
            $table->string('phone_e164', 16)->nullable()->unique();
        });
        Schema::table('sms_codes', function (Blueprint $table): void {
            $table->dropUnique(['mobile_lookup', 'scene']);
            $table->dropColumn('mobile_lookup');
            $table->string('mobile_e164', 16);
            $table->unique(['mobile_e164', 'scene']);
        });
        Schema::dropIfExists('remit_phone_key');
    }
};
