<?php

namespace App\Service\Remittance;

use Illuminate\Support\Facades\Validator;

/** 用途和来源必须由用户明确填写，不能从支付样例或默认值推定。 */
final class RemittanceDeclaration
{
    /** 草稿允许未选项，下单必须完整填写；其他用途必须提供具体说明。 */
    public static function validate(array $input, bool $complete = true): array
    {
        $required = $complete ? 'required' : 'nullable';
        $data = Validator::make(['declaration' => $input], [
            'declaration' => ['array:source_of_funds,purpose,explanation'],
            'declaration.source_of_funds' => [$required, 'string', 'in:01,02,03,04'],
            'declaration.purpose' => [$required, 'string', 'in:01,02,03,04,05'],
            'declaration.explanation' => [$complete && ($input['purpose'] ?? null) === '05' ? 'required' : 'nullable', 'string', 'max:200'],
        ])->validate()['declaration'];

        return [
            'source_of_funds' => $data['source_of_funds'] ?? '',
            'purpose' => $data['purpose'] ?? '',
            'explanation' => trim($data['explanation'] ?? ''),
        ];
    }
}
