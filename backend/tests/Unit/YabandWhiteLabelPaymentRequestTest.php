<?php

namespace Tests\Unit;

use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandWhiteLabelPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InstitutionPaymentFixture;

final class YabandWhiteLabelPaymentRequestTest extends TestCase
{
    public function test_documented_white_label_fields_preserve_json_bytes_and_empty_optional_notes(): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['device'] = ' { "OS":"Android", "brand":"Fixture", "SN":"fixture" } ';
        $input['payee']['explanation'] = '';
        $input['demo'] = '';
        $result = (new YabandWhiteLabelPaymentRequest)->build($input);
        $this->assertSame($input['device'], $result['device']);
        $this->assertSame($input['payee'], json_decode($result['payee'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('100.00', $result['amount']);
    }

    #[DataProvider('unsupportedShapes')]
    public function test_legacy_example_conflicts_and_missing_required_fields_are_not_silently_mapped(array $changes): void
    {
        try {
            (new YabandWhiteLabelPaymentRequest)->build(array_replace(InstitutionPaymentFixture::request(), $changes));
            $this->fail('Invalid white-label data was accepted.');
        } catch (YabandException $error) {
            $this->assertStringContainsString('白标', $error->getMessage());
            $this->assertStringNotContainsString('sensitive-account', $error->getMessage());
        }
    }

    public static function unsupportedShapes(): iterable
    {
        yield 'legacy-amount' => [['source_amount' => '100.00']];
        yield 'legacy-device' => [['device' => 'iOS/17.1']];
        yield 'ambiguous-channel-case' => [['sub_pay_method' => 'IDEAL']];
        yield 'unconfirmed-sepa' => [['sub_pay_method' => 'SEPA']];
        yield 'missing-fee' => [['customer_fee' => null]];
        yield 'missing-notify' => [['notify_url' => null]];
        yield 'floating-money' => [['amount' => 100.0]];
        yield 'bad-payee' => [['payee' => 'sensitive-account']];
    }
}
