<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Repository\System\SmsCodeRepository;
use App\Repository\System\UserRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class PhoneStorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_member_and_active_code_keep_lookup_without_storing_original_phone(): void
    {
        $migration = require database_path('migrations/2026_11_26_000002_protect_member_phone_numbers.php');
        $migration->down();
        $phone = '+34600000009';
        $member = DB::table('users')->insertGetId(['name' => 'Migration Fixture', 'email' => 'migration@example.test',
            'password' => 'fixture-hash', 'phone_e164' => $phone]);
        DB::table('sms_codes')->insert(['mobile_e164' => $phone, 'scene' => 'login', 'code_hash' => str_repeat('a', 64),
            'attempts' => 2, 'expires_at' => now()->addMinute(), 'sent_at' => now()]);
        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'phone_e164'));
        $this->assertFalse(Schema::hasColumn('sms_codes', 'mobile_e164'));
        $current = User::query()->findOrFail($member);
        $this->assertSame($phone, $current->phone_e164);
        $this->assertStringNotContainsString($phone, $current->getRawOriginal('phone_cipher'));
        $this->assertSame($member, app(UserRepository::class)->findOrRegisterMobile($phone)->id);
        $this->assertSame(2, app(SmsCodeRepository::class)->lockActive($phone, 'login')->attempts);
    }

    public function test_lookup_key_change_fails_before_duplicate_registration(): void
    {
        $phone = '+34600000009';
        $members = app(UserRepository::class);
        $members->findOrRegisterMobile($phone);
        config(['remit.phone_lookup_key' => base64_encode(random_bytes(32))]);
        try {
            $members->findOrRegisterMobile($phone);
            $this->fail('Unexpected lookup key change was accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('手机号查询密钥与数据库配置不一致。', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 1);
    }
}
