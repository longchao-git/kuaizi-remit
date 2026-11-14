<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property array $settings
 * @property int|null $revision_id
 */
final class RemittanceRule extends Model
{
    protected $table = 'remittance_rules';

    protected $casts = ['settings' => 'array'];
}
