<?php

namespace Tests\Feature;

use App\Service\Sms\SmsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SmsCodeTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '+34600000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => true, 'remit.sms.test_autofill' => true]);
    }

    public function test_code_is_single_use_and_expires_after_five_minutes(): void
    {
        $code = $this->sendCode();
        $this->postJson('/api/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => $code])
            ->assertJsonPath('code', 0);

        $this->travel(61)->seconds();
        $code = $this->sendCode();
        $this->travel(301)->seconds();
        $this->postJson('/api/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => $code])
            ->assertJsonPath('code', 1001);
    }

    public function test_code_is_invalidated_after_five_failed_attempts(): void
    {
        $code = $this->sendCode();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '000000'])
                ->assertJsonPath('code', 1001);
        }

        $this->travel(61)->seconds();
        $this->postJson('/api/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => $code])
            ->assertJsonPath('code', 1001);
    }

    public function test_send_is_throttled_per_phone_number(): void
    {
        $this->sendCode();

        $this->postJson('/api/app/auth/sms-code', ['mobile' => self::MOBILE])
            ->assertStatus(429)
            ->assertJsonPath('code', 429);

        $this->travel(61)->seconds();
        $this->sendCode();
    }

    public function test_test_code_is_only_returned_when_autofill_is_enabled(): void
    {
        config(['remit.sms.test_autofill' => false]);

        $data = $this->postJson('/api/app/auth/sms-code', ['mobile' => self::MOBILE])
            ->assertJsonPath('data.sent', true)
            ->json('data');

        $this->assertArrayNotHasKey('test_code', $data);
    }

    public function test_send_returns_business_error_when_channel_is_unavailable(): void
    {
        $this->app->instance(SmsChannel::class, new class implements SmsChannel
        {
            public function send(string $mobileE164, string $code, string $scene): void
            {
                throw new RuntimeException('channel down');
            }
        });

        $this->postJson('/api/app/auth/sms-code', ['mobile' => self::MOBILE])
            ->assertJsonPath('code', 503);
        $this->assertDatabaseCount('sms_codes', 0);
    }

    private function sendCode(): string
    {
        return $this->postJson('/api/app/auth/sms-code', ['mobile' => self::MOBILE])
            ->assertJsonPath('data.sent', true)
            ->json('data.test_code');
    }
}
