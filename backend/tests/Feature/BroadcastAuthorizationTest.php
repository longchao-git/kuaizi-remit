<?php

namespace Tests\Feature;

use App\Model\System\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_bearer_can_subscribe_only_to_their_own_private_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('app', ['app:session'])->plainTextToken;
        $payload = ['socket_id' => '12.34', 'channel_name' => 'private-users.'.$user->id];

        $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            ...$payload,
            'channel_name' => 'private-users.'.$other->id,
        ])->assertForbidden();
        $this->postJson('/api/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}
