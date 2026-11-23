<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Providers\AppServiceProvider;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class YabandResponseSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array', 'broadcasting.default' => 'log',
            'yaband_remit.base_url' => 'https://identity.example.test',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.password' => 'fixture-password',
            'yaband_remit.secret_key' => 'fixture-secret',
        ]);
        $this->app->register(AppServiceProvider::class, true);
        Http::preventStrayRequests();
        Http::fake(['*/api/auth/login' => Http::response(['status' => true, 'code' => 200, 'data' => [
            'token' => 'fixture-token', 'token_type' => 'bearer',
            'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]])]);
    }

    #[DataProvider('conflictingUsers')]
    public function test_query_cannot_use_a_conflicting_nested_identity(array|string $user): void
    {
        $this->fakeResponse('/api/kyc/query', ['uid' => '1001', 'user' => $user]);
        $this->expectException(YabandException::class);
        app(YabandRemitClient::class)->queryKyc('fixture-request', '1001');
    }

    public static function conflictingUsers(): iterable
    {
        yield 'object different UID' => [['uid' => '1002']];
        yield 'JSON string different UID' => ['{"uid":"1002"}'];
        yield 'object null UID' => [['uid' => null]];
        yield 'object malformed UID' => [['uid' => ['1001']]];
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_malformed_top_level_uid_raises_a_controlled_provider_error(mixed $uid): void
    {
        $this->fakeResponse('/api/kyc/query', ['uid' => $uid]);
        $this->expectException(YabandException::class);
        app(YabandRemitClient::class)->queryKyc('fixture-request', '1001');
    }

    public static function invalidIdentifiers(): iterable
    {
        yield 'array' => [['1001']];
        yield 'float matching after coercion' => [1001.0];
        yield 'null' => [null];
        yield 'boolean' => [true];
    }

    public function test_idv_rejects_conflicting_top_level_identity_even_when_nested_uid_matches(): void
    {
        $this->fakeResponse('/api/kyc/idv', [
            'uid' => '1002', 'user' => ['uid' => '1001'], 'url' => 'https://idv.example.test/session',
        ]);
        $this->expectException(YabandException::class);
        app(YabandRemitClient::class)->startIdv('fixture-request', '1001', 'https://app.example.test/return', 'https://app.example.test/notify');
    }

    public function test_profile_rejects_conflicting_nested_identity(): void
    {
        $this->fakeResponse('/api/kyc/profile', ['uid' => '1001', 'user' => ['uid' => '1002']]);
        $this->expectException(YabandException::class);
        app(YabandRemitClient::class)->submitProfile('fixture-request', '1001', 'https://app.example.test/notify', []);
    }

    public function test_creation_cannot_bind_conflicting_top_level_and_nested_user_ids(): void
    {
        $this->fakeResponse('/api/kyc/new', ['uid' => '1002', 'user' => ['uid' => '1001']]);
        $this->expectException(YabandException::class);
        app(YabandRemitClient::class)->createMember('fixture-request', 'https://app.example.test/notify', ['external_uid' => 'fixture-member']);
    }

    public function test_matching_object_and_json_string_identifiers_remain_compatible(): void
    {
        Http::fake(['*/api/kyc/query' => Http::sequence()
            ->push(['status' => true, 'code' => 200, 'data' => ['uid' => 1001, 'user' => ['uid' => '1001'], 'kyc_status_code' => '5004']])
            ->push(['status' => true, 'code' => 200, 'data' => ['uid' => '1001', 'user' => '{"uid":1001}', 'kyc_status_code' => '5004']])
            ->push(['status' => true, 'code' => 200, 'data' => ['uid' => '1001', 'user' => '{"images":"[]"}', 'kyc_status_code' => '5004']]),
        ]);
        foreach (range(1, 3) as $request) {
            $this->assertTrue(app(YabandRemitClient::class)->queryKyc('fixture-request', '1001')['kyc_passed']);
        }
    }

    public function test_conflicting_user_does_not_update_member_state_or_send_approval_notices(): void
    {
        $member = User::factory()->create();
        $members = app(YabandRemitMemberRepository::class);
        $link = $members->getOrCreate($member->id);
        $before = ['kyc_passed' => false, 'remittance_enabled' => false];
        $members->saveState($link, '1001', $before);
        $this->fakeResponse('/api/kyc/query', [
            'uid' => '1001', 'user' => ['uid' => '1002'], 'user_status_code' => '1001',
            'remit_status_code' => '4001', 'kyc_status_code' => '5004', 'idv_status_code' => '2006',
        ]);
        $this->withToken($member->createToken('app')->plainTextToken)->getJson('/app/kyc/status')->assertStatus(502);
        $this->assertSame($before, $link->fresh()->statuses);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    private function fakeResponse(string $path, array $data): void
    {
        Http::fake(['*'.$path => Http::response(
            json_encode(['status' => true, 'code' => 200, 'data' => $data], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            200, ['Content-Type' => 'application/json'],
        )]);
    }
}
