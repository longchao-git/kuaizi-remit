<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Model;

/** 仅后台可读的协议草稿，与公开发布版本分开保存。 */
final class AgreementDraft extends Model
{
    protected $table = 'remit_agreement_drafts';

    protected $fillable = ['kind', 'body', 'revision', 'updated_by'];
}
