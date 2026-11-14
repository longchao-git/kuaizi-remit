<?php

namespace App\Repository\Notification;

use App\Model\Notification\MemberPushDevice;
use App\Repository\AbstractRepository;
use App\Service\System\MemberSessionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;

/** @extends AbstractRepository<MemberPushDevice> */
final class MemberPushDeviceRepository extends AbstractRepository
{
    protected function model(): MemberPushDevice
    {
        return new MemberPushDevice;
    }

    public function bind(int $member, int $token, string $cid): void
    {
        PersonalAccessToken::query()->lockForUpdate()->findOrFail($token);
        $key = hash('sha256', $cid);
        $this->query()->where('token_id', $token)->where('client_hash', '!=', $key)->delete();
        $this->query()->upsert([[
            'client_hash' => $key, 'member_id' => $member, 'token_id' => $token, 'client_id' => Crypt::encryptString($cid),
        ]], ['client_hash'], ['member_id', 'token_id', 'client_id', 'updated_at']);
    }

    public function unbind(int $member, int $token): void
    {
        $this->query()->where('member_id', $member)->where('token_id', $token)->delete();
    }

    /** @return array<int, array{id: int, token_id: int}> */
    public function recipients(int $member): array
    {
        return $this->active($member)->orderBy('id')->get(['id', 'token_id'])
            ->map(static fn (MemberPushDevice $device) => ['id' => $device->id, 'token_id' => $device->token_id])->all();
    }

    public function recipient(int $member, int $device, int $token): ?MemberPushDevice
    {
        return $this->active($member)->where('token_id', $token)->find($device);
    }

    private function active(int $member): Builder
    {
        $sessions = app(MemberSessionService::class);
        $tokens = PersonalAccessToken::query()->select('id')->where('tokenable_id', $member)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if (! $sessions->localSmsEnabled()) {
            $tokens->where('name', '!=', MemberSessionService::LOCAL_SMS_TOKEN_NAME);
        }
        $localEmail = $sessions->localLoginEmail();
        $tokens->where(fn (Builder $query) => $query->where('name', '!=', MemberSessionService::LOCAL_TOKEN_NAME)
            ->orWhereIn('tokenable_id', fn ($user) => $user->select('id')->from('users')->where('email', $localEmail)));

        return $this->query()->where('member_id', $member)->whereIn('token_id', $tokens);
    }
}
