<?php

namespace App\Model\System;

use App\Service\System\PhoneLookup;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username 后台专用登录名，不通过会员资料接口分配
 * @property string|null $phone_e164
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_platform_staff
 * @property string|null $platform_role
 * @property Carbon|null $member_closed_at 会员注销完成后禁止登录，历史业务数据保留
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'phone_e164'])]
#[Hidden(['password', 'remember_token', 'phone_cipher', 'phone_lookup'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    /** 业务内部仍读取标准号码，模型序列化只使用显式脱敏视图。 */
    protected function phoneE164(): Attribute
    {
        return Attribute::make(
            get: static fn ($value, array $attributes): ?string => isset($attributes['phone_cipher'])
                ? Crypt::decryptString($attributes['phone_cipher']) : null,
            set: static fn (?string $phone): array => [
                'phone_cipher' => $phone === null ? null : Crypt::encryptString($phone),
                'phone_lookup' => $phone === null ? null : app(PhoneLookup::class)->digest($phone),
            ],
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_staff' => 'boolean',
            'member_closed_at' => 'datetime',
        ];
    }
}
