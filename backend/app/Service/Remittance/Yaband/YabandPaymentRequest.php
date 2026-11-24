<?php

namespace App\Service\Remittance\Yaband;

use Illuminate\Support\Str;
use JsonException;
use stdClass;

/** 只复用机构与白标字段表明确一致的格式规则，不推断成交、核验或渠道权限。 */
abstract class YabandPaymentRequest
{
    /** 具体模式提供固定名称，只用于脱敏错误提示。 */
    abstract protected function modeLabel(): string;

    private const PAYMENT_METHODS = ['token', 'Card', 'Google Pay', 'Apple Pay', 'iDEAL', 'Bancontact', 'Blik', 'EPS', 'Bizum'];

    /** 完整请求在出网前校验UUID及唯一订单编号，保留业务层生成的幂等标识。 */
    public function build(array $data): array
    {
        $data = $this->prepareFields($data);
        $this->text($data, 'request_id', 64);
        $this->text($data, 'order_id', 64);
        if (! Str::isUuid($data['request_id'])) {
            $this->invalid('request_id');
        }

        return $data;
    }

    /** 在本地预留订单及优惠券前校验业务字段，金额始终保留为十进制字符串。 */
    public function prepareFields(array $data): array
    {
        $this->knownFields($data, [
            'request_id', 'order_id', 'type', 'uid', 'description', 'timeout', 'email', 'pay_method',
            'sub_pay_method', 'cardinfo', 'gettoken', 'payment_token_object', 'amount', 'discount_amount',
            'currency', 'customer_fee', 'redirect_url', 'notify_url', 'demo', 'ip', 'device', 'payee',
        ]);
        foreach (['uid' => 64, 'description' => 200, 'timeout' => 64, 'sub_pay_method' => 64, 'currency' => 64, 'ip' => 64] as $key => $length) {
            $this->text($data, $key, $length);
        }
        if (! preg_match('/\A[0-9]{1,4}\z/', $data['timeout']) || (int) $data['timeout'] < 1 || (int) $data['timeout'] > 1440) {
            $this->invalid('timeout');
        }
        if (! in_array($data['sub_pay_method'], self::PAYMENT_METHODS, true)) {
            $this->invalid('sub_pay_method');
        }
        $this->decimal($data, 'amount', positive: true);
        $this->decimal($data, 'customer_fee');
        if (array_key_exists('discount_amount', $data)) {
            $this->decimal($data, 'discount_amount');
        }
        $this->code($data, 'currency', '/\A[A-Z]{3}\z/');
        foreach (['redirect_url', 'notify_url'] as $key) {
            $this->text($data, $key, 200);
            if (! filter_var($data[$key], FILTER_VALIDATE_URL) || parse_url($data[$key], PHP_URL_SCHEME) !== 'https'
                || parse_url($data[$key], PHP_URL_USER) !== null) {
                $this->invalid($key);
            }
        }
        if (! filter_var($data['ip'], FILTER_VALIDATE_IP)) {
            $this->invalid('ip');
        }
        if (array_key_exists('email', $data)) {
            $this->text($data, 'email', 64);
            if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->invalid('email');
            }
        }
        if (array_key_exists('demo', $data)) {
            $this->text($data, 'demo', 200, allowEmpty: true);
        }

        [$device, $data['device']] = $this->jsonObject($data, 'device', 200);
        $this->knownFields($device, ['OS', 'brand', 'SN']);
        foreach (['OS', 'brand', 'SN'] as $key) {
            $this->text($device, $key, 200, 'device.');
        }
        [$payee, $data['payee']] = $this->jsonObject($data, 'payee', 500);
        $this->payee($payee);
        $this->paymentCredentials($data);

        return [...$data, 'type' => 'transaction', 'pay_method' => 'online'];
    }

    /** 只检验收款信息格式及枚举，不把协议中的本人关系值当作实名核验结果。 */
    private function payee(array $payee): void
    {
        $this->knownFields($payee, [
            'first_name', 'last_name', 'channel', 'account', 'amount', 'currency', 'exchange_rate', 'country',
            'relationship', 'source_of_funds', 'purpose_and_explanation_of_the_transfer', 'explanation', 'remittance_reference',
        ]);
        foreach (['first_name', 'last_name', 'channel', 'account', 'currency', 'exchange_rate', 'country', 'relationship', 'source_of_funds', 'purpose_and_explanation_of_the_transfer'] as $key) {
            $this->text($payee, $key, 64, 'payee.');
        }
        $this->decimal($payee, 'amount', positive: true, prefix: 'payee.');
        $this->code($payee, 'currency', '/\A[A-Z]{3}\z/', 'payee.');
        $this->code($payee, 'country', '/\A[A-Z]{2}\z/', 'payee.');
        $this->code($payee, 'channel', '/\A0[0-3]\z/', 'payee.');
        $this->code($payee, 'relationship', '/\A(?:0[1-9]|1[0-4])\z/', 'payee.');
        $this->code($payee, 'source_of_funds', '/\A0[1-4]\z/', 'payee.');
        $this->code($payee, 'purpose_and_explanation_of_the_transfer', '/\A0[1-5]\z/', 'payee.');
        if (! preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $payee['exchange_rate']) || trim($payee['exchange_rate'], '0.') === '') {
            $this->invalid('payee.exchange_rate');
        }
        if ($payee['purpose_and_explanation_of_the_transfer'] === '05' || array_key_exists('explanation', $payee)) {
            $this->text($payee, 'explanation', 200, 'payee.', allowEmpty: $payee['purpose_and_explanation_of_the_transfer'] !== '05');
        }
        if (array_key_exists('remittance_reference', $payee)) {
            $this->text($payee, 'remittance_reference', 200, 'payee.', allowEmpty: true);
        }
    }

    /** 按支付方式检验条件字段；不保存卡号、安全码或Token，不生成虚构凭据。 */
    private function paymentCredentials(array &$data): void
    {
        if (array_key_exists('gettoken', $data) && ! is_bool($data['gettoken'])) {
            $this->invalid('gettoken');
        }
        if ($data['sub_pay_method'] === 'Card' || array_key_exists('cardinfo', $data)) {
            [$card, $data['cardinfo']] = $this->jsonObject($data, 'cardinfo', 300);
            $this->knownFields($card, ['card_holder', 'card_number', 'expiration_month', 'expiration_year', 'cvc']);
            foreach (['card_holder' => 64, 'card_number' => 64, 'expiration_month' => 2, 'expiration_year' => 4, 'cvc' => 3] as $key => $length) {
                $this->text($card, $key, $length, 'cardinfo.');
            }
            $this->code($card, 'card_number', '/\A[0-9]+\z/', 'cardinfo.');
            $this->code($card, 'expiration_month', '/\A(?:0[1-9]|1[0-2])\z/', 'cardinfo.');
            $this->code($card, 'expiration_year', '/\A[0-9]{4}\z/', 'cardinfo.');
            $this->code($card, 'cvc', '/\A[0-9]{3}\z/', 'cardinfo.');
        }
        if ($data['sub_pay_method'] === 'Card' && ! array_key_exists('gettoken', $data)) {
            $this->invalid('gettoken');
        }
        if (in_array($data['sub_pay_method'], ['Apple Pay', 'Google Pay', 'token'], true) || array_key_exists('payment_token_object', $data)) {
            $this->text($data, 'payment_token_object', 1000);
        }
    }

    /** 数组只序列化一次，已提供的JSON字符串逐字保留用于签名与传输。 */
    private function jsonObject(array $data, string $key, int $length): array
    {
        $value = $data[$key] ?? null;
        try {
            if (is_array($value) && ! array_is_list($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $this->text([$key => $value], $key, $length);
            $decoded = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid($key);
        }
        if (! $decoded instanceof stdClass) {
            $this->invalid($key);
        }

        return [(array) $decoded, $value];
    }

    /** 严格限制文本类型及字符数，不修剪签名字段或将其他类型转换为字符串。 */
    private function text(array $data, string $key, int $length, string $prefix = '', bool $allowEmpty = false): void
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || (! $allowEmpty && trim($value) === '') || mb_strlen($value, 'UTF-8') > $length) {
            $this->invalid($prefix.$key);
        }
    }

    /** 金额不得通过浮点、科学计数或四舍五入补齐，正数检查直接基于数字字符。 */
    private function decimal(array $data, string $key, bool $positive = false, string $prefix = ''): void
    {
        $this->text($data, $key, 64, $prefix);
        if (! preg_match('/\A[0-9]+\.[0-9]{2}\z/', $data[$key]) || ($positive && trim($data[$key], '0.') === '')) {
            $this->invalid($prefix.$key);
        }
    }

    /** 枚举和格式区分大小写，禁止将未确认支付方式或渠道自动映射。 */
    private function code(array $data, string $key, string $pattern, string $prefix = ''): void
    {
        if (! preg_match($pattern, $data[$key])) {
            $this->invalid($prefix.$key);
        }
    }

    /** 拒绝未纳入协议的字段，避免准备适配器误传敏感数据或签名控制字段。 */
    private function knownFields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new YabandException('雅本'.$this->modeLabel().'支付包含未支持的参数。');
        }
    }

    /** 错误仅包含由代码指定的字段名，不拼接收款账户、卡号或Token。 */
    private function invalid(string $key): never
    {
        throw new YabandException('雅本'.$this->modeLabel().'支付参数无效：'.$key.'。');
    }
}
