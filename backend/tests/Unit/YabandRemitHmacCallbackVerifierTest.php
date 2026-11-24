<?php

namespace Tests\Unit;

use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitHmacCallbackVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YabandRemitHmacCallbackVerifierTest extends TestCase
{
    public function test_scalar_notification_matches_an_independent_canonical_string(): void
    {
        $data = ['type' => 'kyc', 'mid' => 'fixture-mid', 'user_status' => 'active', 'create_at' => 1795404356];
        $canonical = 'create_at=1795404356&mid=fixture-mid&type=kyc&user_status=active';
        $body = ['sign' => hash_hmac('sha256', $canonical, 'fixture-key'), 'data' => $data];
        $verifier = new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid');
        $this->assertTrue($verifier->verifies('kyc', json_encode($body), $body));
        $flat = [...$data, 'sign' => $body['sign']];
        $this->assertTrue($verifier->verifies('kyc', json_encode($flat), $flat));
        $body['data']['user_status'] = 'inactive';
        $this->assertFalse($verifier->verifies('kyc', json_encode($body), $body));
    }

    public function test_nested_json_uses_default_escaping_and_keeps_null_values(): void
    {
        $data = ['type' => 'kyc', 'mid' => 'fixture-mid', 'extra' => ['name' => '测/试', 'nil' => null, 'flag' => true], 'list' => ['测/试', null, true, false, 1.25]];
        $canonical = 'extra={"name":"\u6d4b\/\u8bd5","nil":null,"flag":true}&list=["\u6d4b\/\u8bd5",null,true,false,1.25]&mid=fixture-mid&type=kyc';
        $body = ['data' => $data, 'sign' => hash_hmac('sha256', $canonical, 'fixture-key')];
        $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertTrue((new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid'))->verifies('kyc', $raw, $body));
        $body['data']['extra']['name'] = 'changed';
        $this->assertFalse((new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid'))->verifies('kyc', json_encode($body), $body));
    }

    public function test_json_strings_are_not_trimmed_decoded_or_reserialized(): void
    {
        $user = ' { "uid":101, "name":"测/试", "nil":null } ';
        $data = ['mid' => 'fixture-mid', 'type' => 'kyc', 'user' => $user, 'false' => false, 'true' => true, 'optional' => null];
        $canonical = 'false=false&mid=fixture-mid&optional=&true=true&type=kyc&user='.$user;
        $body = ['data' => $data, 'sign' => hash_hmac('sha256', $canonical, 'fixture-key')];
        $verifier = new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid');
        $this->assertTrue($verifier->verifies('kyc', json_encode($body), $body));
        $body['data']['user'] = trim($user);
        $this->assertFalse($verifier->verifies('kyc', json_encode($body), $body));
    }

    public function test_only_data_is_signed_and_unsigned_envelope_fields_are_not_business_fields(): void
    {
        $data = ['mid' => 'fixture-mid', 'type' => 'transaction', 'order_id' => 'fixture-order', 'sign' => 'ignored-inner-sign'];
        $body = ['mid' => 'ignored-outer-mid', 'time' => 123, 'data' => $data,
            'sign' => hash_hmac('sha256', 'mid=fixture-mid&order_id=fixture-order&type=transaction', 'fixture-key')];
        $this->assertTrue((new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid'))->verifies('transaction', json_encode($body), $body));
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_mismatched_payload_is_rejected(string $raw, array $payload, string $event = 'kyc'): void
    {
        $this->assertFalse((new YabandRemitHmacCallbackVerifier('fixture-key', 'fixture-mid'))->verifies($event, $raw, $payload));
    }

    public static function invalidInputs(): iterable
    {
        $body = ['data' => ['mid' => 'fixture-mid', 'type' => 'kyc'], 'sign' => hash_hmac('sha256', 'mid=fixture-mid&type=kyc', 'fixture-key')];
        yield 'unknown-event' => [json_encode($body), $body, 'materials'];
        yield 'event-mismatch' => [json_encode($body), $body, 'transaction'];
        yield 'bad-json' => ['{', $body];
        yield 'wrong-root' => ['[]', $body];
        yield 'raw-payload-differ' => [json_encode($body), [...$body, 'extra' => true]];
        yield 'too-large' => [str_repeat(' ', 262145), $body];
        foreach ([null, '', [], 123, str_repeat('0', 64), strtoupper($body['sign'])] as $signature) {
            $changed = [...$body, 'sign' => $signature];
            yield 'signature-'.get_debug_type($signature).'-'.count(is_array($signature) ? $signature : (array) $signature).'-'.substr(hash('sha256', serialize($signature)), 0, 6) => [json_encode($changed), $changed];
        }
        foreach ([null, [], ['value'], 'json', 1] as $data) {
            $changed = [...$body, 'data' => $data];
            yield 'data-'.get_debug_type($data).'-'.substr(hash('sha256', serialize($data)), 0, 6) => [json_encode($changed), $changed];
        }
        foreach (['another-mid', 123, null, ['fixture-mid']] as $mid) {
            $changed = $body;
            $changed['data']['mid'] = $mid;
            yield 'mid-'.get_debug_type($mid) => [json_encode($changed), $changed];
        }
    }

    public function test_missing_configuration_fails_without_exposing_key(): void
    {
        $this->expectException(YabandException::class);
        new YabandRemitHmacCallbackVerifier('', 'fixture-mid');
    }
}
