<?php

use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\PersonalAccessToken;

Broadcast::channel('users.{id}', function (User $user, string $id): bool {
    return $user->currentAccessToken() instanceof PersonalAccessToken
        && (string) $user->getKey() === $id;
}, ['guards' => ['sanctum']]);

Broadcast::channel('support.staff', function (User $user): bool {
    return PlatformRoles::allows($user, $user->currentAccessToken(), RemittancePermission::SUPPORT);
}, ['guards' => ['sanctum']]);
