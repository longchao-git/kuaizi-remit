<?php

namespace Tests\Unit;

use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandFailureMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YabandFailureMessageTest extends TestCase
{
    #[DataProvider('failures')]
    public function test_only_documented_contexts_produce_fixed_safe_messages(string $path, int $code, string $fragment): void
    {
        $message = YabandFailureMessage::forException(new YabandException('secret identity and card', $code, $path), '原有提示');
        $this->assertStringContainsString($fragment, $message);
        $this->assertStringNotContainsString('secret', $message);
    }

    public static function failures(): iterable
    {
        yield 'new-compliance' => ['/api/kyc/new', -1005, '开通认证'];
        yield 'query-not-active' => ['/api/kyc/query', -1005, '未激活或无法找到'];
        yield 'observed-query-code' => ['/api/kyc/query', -1010, '未激活或无法找到'];
        yield 'existing-identity' => ['/api/kyc/new', -1004, '勿重复申请'];
        yield 'signature-error' => ['/api/kyc/query', -1001, '通道校验未通过'];
        yield 'invalid-profile' => ['/api/kyc/profile', -1002, '核对后重试'];
        yield 'bad-question' => ['/api/materials/update', -1030, '补件任务未被识别'];
        yield 'bad-transaction' => ['/api/materials/update', -1031, '关联交易需要核实'];
        yield 'white-channel' => ['/api/whitelable/transaction/payment', -1040, '支付方式不受支持'];
        yield 'institution-declined' => ['/api/transaction/payment', -1041, '付款请求未获受理'];
        yield 'context-mismatch' => ['/api/kyc/new', -1040, '原有提示'];
        yield 'unknown-code' => ['/api/kyc/query', -9999, '原有提示'];
        yield 'unknown-operation' => ['/api/unknown', -1001, '原有提示'];
    }
}
