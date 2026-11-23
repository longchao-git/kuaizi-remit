<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Service\Sms\SmsChannel;
use App\Service\System\MemberSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class TestSmsLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '+34600000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        config([
            'remit.local_sms_login' => false,
            'remit.test_sms_login.enabled' => true,
            'remit.test_sms_login.mobile' => self::MOBILE,
            'remit.test_sms_login.code' => '123456',
            'yaband_remit.base_url' => 'https://remittestv2.yabandpay.com',
        ]);
        $this->app->instance(SmsChannel::class, new class implements SmsChannel
        {
            public function send(string $mobileE164, string $code, string $scene): void
            {
                throw new RuntimeException('Test login must not send or log an SMS');
            }
        });
        Http::preventStrayRequests();
    }

    public function test_fixed_code_requires_issuance_is_hashed_single_use_and_expires(): void
    {
        $input = ['mobile' => self::MOBILE, 'code' => '123456'];
        $this->postJson('/app/auth/sms-login', $input)->assertJsonPath('code', 1001);
        $this->issue();
        $this->assertNotSame('123456', DB::table('sms_codes')->value('code_hash'));
        $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '000000'])
            ->assertJsonPath('code', 1001);
        $login = $this->postJson('/app/auth/sms-login', $input)->assertJsonPath('code', 0);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => MemberSessionService::TEST_SMS_TOKEN_NAME]);
        $this->postJson('/app/auth/sms-login', $input)->assertJsonPath('code', 1001);
        $this->travel(61)->seconds();
        $this->issue();
        $this->travel(301)->seconds();
        $this->postJson('/app/auth/sms-login', $input)->assertJsonPath('code', 1001);
        $this->assertDatabaseCount('users', 1);
        $this->withToken($login->json('data.token'))->getJson('/app/auth/current')->assertJsonPath('code', 0);
        Http::assertNothingSent();
    }

    public function test_only_configured_phone_can_request_or_use_test_codes(): void
    {
        $other = ['mobile' => '+34600000001'];
        $this->postJson('/app/auth/sms-code', $other)->assertJsonPath('code', 403);
        $this->postJson('/app/auth/sms-login', $other + ['code' => '123456'])->assertJsonPath('code', 1001);
        $this->postJson('/app/auth/sms-code', ['mobile' => '+33600000000'])->assertUnprocessable();
        $this->postJson('/app/auth/sms-code', [])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('sms_codes', 0);
        Http::assertNothingSent();
    }

    public function test_test_login_requires_explicit_complete_configuration_and_exact_sandbox_url(): void
    {
        foreach ([
            ['remit.test_sms_login.enabled' => false],
            ['remit.test_sms_login.enabled' => true, 'yaband_remit.base_url' => 'https://remit.yabandpay.com'],
            ['yaband_remit.base_url' => 'https://remittestv2.yabandpay.com.evil.example'],
            ['yaband_remit.base_url' => 'https://remittestv2.yabandpay.com', 'remit.test_sms_login.code' => ''],
            ['remit.test_sms_login.code' => '123456', 'remit.test_sms_login.mobile' => ''],
        ] as $configuration) {
            config($configuration);
            $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '123456'])
                ->assertNotFound()->assertJsonPath('msg', '短信登录暂未开放');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_ordinary_members_and_staff_cannot_be_taken_over(): void
    {
        foreach ([false, true] as $staff) {
            $member = User::factory()->create(['phone_e164' => self::MOBILE, 'is_platform_staff' => $staff]);
            $before = $member->fresh()->getAttributes();
            $this->issue();
            $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '123456'])
                ->assertJsonPath('code', 1001);
            $this->assertSame($before, $member->fresh()->getAttributes());
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $member->delete();
            $this->travel(61)->seconds();
        }
    }

    public function test_session_restores_on_both_paths_and_is_revoked_when_test_mode_is_closed(): void
    {
        $token = $this->login();
        foreach (['', '/api'] as $prefix) {
            $this->withToken($token)->getJson($prefix.'/app/auth/current')->assertJsonPath('code', 0);
            $this->getJson($prefix.'/app/coupons')->assertJsonPath('code', 0)->assertJsonPath('data.total', 0);
            $this->getJson($prefix.'/app/support/tickets')->assertJsonPath('code', 0);
            $this->getJson($prefix.'/app/notifications')->assertJsonPath('code', 0);
            $this->getJson($prefix.'/user/current')->assertForbidden();
        }
        config(['remit.test_sms_login.mobile' => '+34600000001']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/app/auth/current')->assertUnauthorized();
        config(['remit.test_sms_login.mobile' => self::MOBILE, 'remit.test_sms_login.enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/auth/current')->assertUnauthorized();
    }

    public function test_logout_revokes_test_session_on_canonical_and_legacy_paths(): void
    {
        $token = $this->login();
        $this->withToken($token)->postJson('/app/auth/logout')->assertJsonPath('code', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/auth/current')->assertUnauthorized();
    }

    public function test_test_session_cannot_start_identity_or_funds_operations_on_either_path(): void
    {
        $this->withToken($this->login());
        foreach (['', '/api'] as $prefix) {
            foreach (['/app/kyc/idv', '/app/kyc/profile', '/app/remittance/quotes', '/app/remittance/orders',
                '/app/remittance/materials/469b1f89-6614-4862-8e71-e03f974b909e'] as $path) {
                $this->postJson($prefix.$path, [])->assertForbidden()
                    ->assertJsonPath('msg', '测试账号暂不支持实名认证或真实资金操作');
            }
            $this->getJson($prefix.'/app/remittance/payout-accounts')->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_fixed_code_keeps_sending_and_verification_limits(): void
    {
        $this->issue();
        $this->postJson('/app/auth/sms-code', ['mobile' => self::MOBILE])->assertStatus(429);
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '000000'])
                ->assertJsonPath('code', 1001);
        }
        $this->travel(61)->seconds();
        $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '123456'])
            ->assertJsonPath('code', 1001);
        $this->assertDatabaseCount('users', 0);
    }

    private function issue(): void
    {
        $this->postJson('/app/auth/sms-code', ['mobile' => self::MOBILE])->assertJsonPath('code', 0)
            ->assertJsonPath('data.sent', true)->assertJsonMissingPath('data.test_code')
            ->assertJsonPath('msg', '测试验证码已准备，请输入约定测试码');
    }

    private function login(): string
    {
        $this->issue();

        return $this->postJson('/app/auth/sms-login', ['mobile' => self::MOBILE, 'code' => '123456'])
            ->assertJsonPath('code', 0)->json('data.token');
    }
}
