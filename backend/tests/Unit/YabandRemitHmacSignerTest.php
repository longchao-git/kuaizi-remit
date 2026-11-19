<?php

namespace Tests\Unit;

use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitHmacSigner;
use PHPUnit\Framework\TestCase;

final class YabandRemitHmacSignerTest extends TestCase
{
    public function test_signature_matches_the_published_request_example(): void
    {
        $payload = [
            'mid' => '000001',
            'time' => 1756878556,
            'sign' => 'ignored',
            'data' => [
                'type' => 'kyc',
                'request_id' => 'e28a5ce5-87f3-47b3-8a60-c0c3dd44a5c1',
                'notify_url' => 'https://example.com/webhooks/kyc',
                'user' => [
                    'external_uid' => '13516541654', 'email' => 'user@example.com', 'phone' => '+31612345678',
                    'first_name' => 'San', 'last_name' => 'Zhang', 'registration_date' => '2025-08-23',
                    'gender' => 'M', 'birthday' => '1987-12-28', 'nationality' => 'CN',
                ],
            ],
        ];

        $this->assertSame(
            '79286e47d653117c9fc53a922358af48da95996d87d5c4e430bdb78dddfb904d',
            (new YabandRemitHmacSigner('YOUR_SECRET_KEY'))->sign('/api/kyc/new', $payload),
        );
    }

    public function test_nested_json_keeps_input_order_unicode_slashes_and_array_nulls(): void
    {
        $payload = [
            'mid' => '商户/一&x', 'time' => 12, 's' => 'excluded',
            'data' => [
                'z' => null, 'enabled' => false,
                'user' => (object) ['last' => '张/三', 'first' => null, 'list' => [null, true, false], 'value' => '0'],
            ],
        ];
        $canonical = 'enabled=false&mid=商户/一&x&time=12&user={"last":"张/三","first":"","list":[null,true,false],"value":"0"}&z=';

        $this->assertSame(
            hash_hmac('sha256', $canonical, 'fixture-key'),
            (new YabandRemitHmacSigner('fixture-key'))->sign('/fixture', $payload),
        );
    }

    public function test_top_level_fields_override_data_and_empty_s_participates(): void
    {
        $payload = ['mid' => 'top', 's' => '', 'data' => ['mid' => 'nested', 'a' => true, 'Z' => 'last'], 'sign' => 'excluded'];
        $canonical = 'Z=last&a=true&mid=top&s=';

        $this->assertSame(
            hash_hmac('sha256', $canonical, 'fixture-key'),
            (new YabandRemitHmacSigner('fixture-key'))->sign('/fixture', $payload),
        );
    }

    public function test_numeric_keys_use_php_default_sorting_and_json_strings_remain_unchanged(): void
    {
        $payload = ['mid' => 'fixture', 'data' => [10 => 'ten', 2 => 'two', 'user' => ' { "uid": 1, "name":null } ']];
        $canonical = '2=two&10=ten&mid=fixture&user= { "uid": 1, "name":null } ';

        $this->assertSame(
            hash_hmac('sha256', $canonical, 'fixture-key'),
            (new YabandRemitHmacSigner('fixture-key'))->sign('/fixture', $payload),
        );
    }

    public function test_invalid_nested_json_fails_without_exposing_parameters_or_secret(): void
    {
        $this->expectException(YabandException::class);
        $this->expectExceptionMessage('雅本请求参数无法序列化为签名数据。');
        (new YabandRemitHmacSigner('fixture-secret'))->sign('/fixture', ['data' => ['user' => ['invalid' => NAN]]]);
    }
}
