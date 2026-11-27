<?php

namespace App\Model\System;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 短信验证码记录，每个手机号与场景只保留最新一条。
 *
 * @property int $id
 * @property string $mobile_lookup
 * @property string $scene
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon $sent_at
 * @property string|null $ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['mobile_lookup', 'scene', 'code_hash', 'attempts', 'expires_at', 'sent_at', 'ip'])]
#[Hidden(['mobile_lookup', 'code_hash', 'ip'])]
final class SmsCode extends Model
{
    protected $table = 'sms_codes';

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
