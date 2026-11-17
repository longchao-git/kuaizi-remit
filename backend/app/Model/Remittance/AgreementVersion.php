<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Model;

/** 后台发布后供 App 读取的协议版本。 */
final class AgreementVersion extends Model
{
    protected $table = 'remit_agreement_versions';

    public $timestamps = false;

    protected $fillable = ['kind', 'body', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime'];
    }
}
