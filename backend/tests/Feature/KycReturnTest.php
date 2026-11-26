<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KycReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_return_page_does_not_treat_redirect_parameters_as_authentication_results(): void
    {
        Http::preventStrayRequests();
        $query = http_build_query([
            'status' => 'approved', 'kyc_status_code' => '5004', 'uid' => 'secret-user',
            'token' => 'secret-token', 'redirect' => 'https://attacker.example.test', 'reason' => '<script>alert(1)</script>',
        ]);
        foreach (['/kyc/return', '/api/kyc/return'] as $path) {
            $response = $this->get($path.'?'.$query)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
                ->assertSee('请返回 App 查看认证进度')->assertSee('本页面不显示认证结果')
                ->assertDontSee('认证通过')->assertDontSee('secret-user')->assertDontSee('secret-token')
                ->assertDontSee('attacker.example.test')->assertDontSee('<script>', false)
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('yaband_remit_members', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertNothingSent();
    }
}
