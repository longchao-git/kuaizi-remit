<?php

namespace Tests\Unit;

use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandInstitutionPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InstitutionPaymentFixture;

final class YabandInstitutionPaymentRequestTest extends TestCase
{
    public function test_arrays_become_json_strings_and_supplied_json_bytes_are_preserved(): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['payee']['first_name'] = '张/三';
        $input['device'] = ' { "SN":"fixture", "brand":"Fixture", "OS":"Android" } ';
        $result = (new YabandInstitutionPaymentRequest)->build($input);
        $this->assertSame($input['device'], $result['device']);
        $this->assertSame(json_encode($input['payee'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $result['payee']);
        $this->assertSame('transaction', $result['type']);
        $this->assertSame('online', $result['pay_method']);
        $this->assertSame('100.00', $result['amount']);
        $this->assertSame($result, (new YabandInstitutionPaymentRequest)->build($result));
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_business_fields_are_rejected_without_leaking_values(array $changes): void
    {
        $input = array_replace_recursive(InstitutionPaymentFixture::request(), $changes);
        try {
            (new YabandInstitutionPaymentRequest)->build($input);
            $this->fail('Invalid institution parameters were accepted.');
        } catch (YabandException $e) {
            $this->assertStringNotContainsString('fixture-account', $e->getMessage());
            $this->assertStringNotContainsString('4111111111111111', $e->getMessage());
            $this->assertStringNotContainsString('sensitive-token', $e->getMessage());
        }
    }

    public static function invalidFields(): iterable
    {
        foreach (['uid', 'description', 'timeout', 'sub_pay_method', 'amount', 'currency', 'customer_fee', 'redirect_url', 'notify_url', 'ip', 'device', 'payee', 'request_id', 'order_id'] as $name) {
            yield 'missing-'.$name => [[$name => null]];
        }
        yield 'unknown-field' => [['unexpected' => 'value']];
        yield 'non-uuid' => [['request_id' => 'fixture-request']];
        yield 'long-order' => [['order_id' => str_repeat('a', 65)]];
        yield 'timeout-over-limit' => [['timeout' => '1441']];
        yield 'timeout-zero' => [['timeout' => '0']];
        yield 'timeout-float' => [['timeout' => 30.0]];
        yield 'method-case' => [['sub_pay_method' => 'IDEAL']];
        yield 'unsupported-sepa' => [['sub_pay_method' => 'SEPA']];
        yield 'numeric-amount' => [['amount' => 100.00]];
        yield 'exponential-amount' => [['amount' => '1e2']];
        yield 'extra-decimal' => [['amount' => '100.001']];
        yield 'negative-amount' => [['amount' => '-100.00']];
        yield 'zero-amount' => [['amount' => '0.00']];
        yield 'negative-fee' => [['customer_fee' => '-0.01']];
        yield 'numeric-discount' => [['discount_amount' => 0]];
        yield 'currency-type' => [['currency' => 978]];
        yield 'http-redirect' => [['redirect_url' => 'http://app.example.test']];
        yield 'url-credentials' => [['notify_url' => 'https://user:password@api.example.test/notify']];
        yield 'invalid-email' => [['email' => 'not-an-email']];
        yield 'invalid-ip' => [['ip' => 'client.example.test']];
        yield 'long-description' => [['description' => str_repeat('a', 201)]];
        yield 'list-device' => [['device' => '["Android","Fixture","SN"]']];
        yield 'scalar-device' => [['device' => 'false']];
        yield 'unknown-device-field' => [['device' => ['unexpected' => 'value']]];
        yield 'missing-device-field' => [['device' => '{"OS":"Android","brand":"Fixture"}']];
        yield 'malformed-payee' => [['payee' => '{']];
        yield 'unknown-payee-field' => [['payee' => ['unexpected' => 'value']]];
        yield 'json-array-payee' => [['payee' => '[]']];
        yield 'payee-name-type' => [['payee' => ['first_name' => ['TEST']]]];
        yield 'payee-channel' => [['payee' => ['channel' => 'bank']]];
        yield 'payee-amount' => [['payee' => ['amount' => '805.401']]];
        yield 'payee-rate-zero' => [['payee' => ['exchange_rate' => '0.000']]];
        yield 'payee-rate-float' => [['payee' => ['exchange_rate' => 8.054]]];
        yield 'payee-country' => [['payee' => ['country' => 'China']]];
        yield 'payee-relationship' => [['payee' => ['relationship' => '15']]];
        yield 'payee-source' => [['payee' => ['source_of_funds' => '05']]];
        yield 'payee-purpose' => [['payee' => ['purpose_and_explanation_of_the_transfer' => '06']]];
        yield 'other-purpose-needs-explanation' => [['payee' => ['purpose_and_explanation_of_the_transfer' => '05']]];
        yield 'device-json-limit' => [['device' => ['SN' => str_repeat('a', 200)]]];
        yield 'payee-json-limit' => [['payee' => ['remittance_reference' => str_repeat('a', 200), 'explanation' => str_repeat('a', 200)]]];
        yield 'card-missing-info' => [['sub_pay_method' => 'Card', 'gettoken' => false]];
        yield 'card-missing-gettoken' => [['sub_pay_method' => 'Card', 'cardinfo' => InstitutionPaymentFixture::card()]];
        yield 'card-nonboolean-gettoken' => [['sub_pay_method' => 'Card', 'cardinfo' => InstitutionPaymentFixture::card(), 'gettoken' => 'false']];
        yield 'card-invalid-month' => [['sub_pay_method' => 'Card', 'cardinfo' => [...InstitutionPaymentFixture::card(), 'expiration_month' => '13'], 'gettoken' => false]];
        yield 'card-invalid-cvc' => [['sub_pay_method' => 'Card', 'cardinfo' => [...InstitutionPaymentFixture::card(), 'cvc' => '1234'], 'gettoken' => false]];
        foreach (['Apple Pay', 'Google Pay', 'token'] as $method) {
            yield $method.'-missing-token' => [['sub_pay_method' => $method]];
            yield $method.'-invalid-token' => [['sub_pay_method' => $method, 'payment_token_object' => ['sensitive-token']]];
        }
    }

    public function test_card_false_gettoken_is_valid_and_no_sensitive_fields_are_rewritten(): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['sub_pay_method'] = 'Card';
        $input['cardinfo'] = json_encode(InstitutionPaymentFixture::card(), JSON_THROW_ON_ERROR);
        $input['gettoken'] = false;
        $result = (new YabandInstitutionPaymentRequest)->build($input);
        $this->assertFalse($result['gettoken']);
        $this->assertSame($input['cardinfo'], $result['cardinfo']);
    }

    #[DataProvider('paymentMethods')]
    public function test_all_documented_payment_methods_are_supported_without_mapping(string $method): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['sub_pay_method'] = $method;
        if ($method === 'Card') {
            $input['cardinfo'] = InstitutionPaymentFixture::card();
            $input['gettoken'] = true;
        }
        if (in_array($method, ['Apple Pay', 'Google Pay', 'token'], true)) {
            $input['payment_token_object'] = 'fixture-token';
        }
        $this->assertSame($method, (new YabandInstitutionPaymentRequest)->build($input)['sub_pay_method']);
    }

    public static function paymentMethods(): array
    {
        return array_map(static fn (string $method): array => [$method], ['token', 'Card', 'Google Pay', 'Apple Pay', 'iDEAL', 'Bancontact', 'Blik', 'EPS', 'Bizum']);
    }

    public function test_large_amounts_keep_exact_decimal_text_and_optional_fields_are_not_invented(): void
    {
        $input = InstitutionPaymentFixture::fields();
        $input['amount'] = '9007199254740993.01';
        $input['payee']['amount'] = '9007199254740994.02';
        $input['payee']['purpose_and_explanation_of_the_transfer'] = '05';
        $input['payee']['explanation'] = '测试用途';
        $result = (new YabandInstitutionPaymentRequest)->prepareFields($input);
        $this->assertSame($input['amount'], $result['amount']);
        $this->assertSame($input['payee']['amount'], json_decode($result['payee'], true)['amount']);
        $this->assertArrayNotHasKey('discount_amount', $result);
        $this->assertArrayNotHasKey('request_id', $result);
        $this->assertArrayNotHasKey('gettoken', $result);
        $this->assertArrayNotHasKey('payment_token_object', $result);
    }

    public function test_documented_boundaries_use_unicode_character_counts_and_allow_zero_fees(): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['description'] = str_repeat('测', 200);
        $input['timeout'] = '1440';
        $input['discount_amount'] = '0.00';
        $input['email'] = 'fixture@example.test';
        $input['ip'] = '2001:db8::1';
        $result = (new YabandInstitutionPaymentRequest)->build($input);
        $this->assertSame($input['description'], $result['description']);
        $this->assertSame('1440', $result['timeout']);
        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertSame('0.00', $result['customer_fee']);
        $this->assertSame('2001:db8::1', $result['ip']);
    }
}
