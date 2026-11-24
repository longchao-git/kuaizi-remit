<?php

namespace Tests\Support;

/** 仅供隔离测试使用的虚构资料，不能作为实际汇款默认值。 */
final class InstitutionPaymentFixture
{
    public static function fields(): array
    {
        return [
            'uid' => '101', 'description' => 'Fixture transfer', 'timeout' => '30',
            'sub_pay_method' => 'iDEAL', 'amount' => '100.00', 'currency' => 'EUR', 'customer_fee' => '0.00',
            'redirect_url' => 'https://app.example.test/payment', 'notify_url' => 'https://api.example.test/notify',
            'ip' => '192.0.2.1', 'device' => ['OS' => 'Android', 'brand' => 'Fixture', 'SN' => 'fixture-device'],
            'payee' => [
                'first_name' => 'TEST', 'last_name' => 'ONLY', 'channel' => '03', 'account' => 'fixture-account',
                'amount' => '805.40', 'currency' => 'CNY', 'exchange_rate' => '8.054', 'country' => 'CN',
                'relationship' => '01', 'source_of_funds' => '02', 'purpose_and_explanation_of_the_transfer' => '03',
            ],
        ];
    }

    public static function request(): array
    {
        return [...self::fields(), 'request_id' => 'fa478bc6-5413-4d8d-89d1-749d6b43eb60', 'order_id' => 'fixture-order'];
    }

    public static function card(): array
    {
        return [
            'card_holder' => 'TEST ONLY', 'card_number' => '4111111111111111',
            'expiration_month' => '01', 'expiration_year' => '2030', 'cvc' => '123',
        ];
    }
}
