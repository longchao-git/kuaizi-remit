<?php

namespace App\Service\Remittance\Yaband;

/** 按已公开接口的业务码给出固定提示，不转发可能含身份或支付资料的上游自由文本。 */
final class YabandFailureMessage
{
    /** 同一码在创建、查询等接口含义不同；未知接口或未知码保持调用方原有提示。 */
    public static function forException(YabandException $error, string $fallback): string
    {
        $path = $error->upstreamPath;
        $kyc = in_array($path, ['/api/kyc/new', '/api/kyc/query', '/api/kyc/idv', '/api/kyc/profile'], true);
        $payment = in_array($path, ['/api/transaction/payment', '/api/whitelable/transaction/payment'], true);
        $query = in_array($path, ['/api/transaction/query', '/api/whitelable/transaction/query'], true);
        $materials = $path === '/api/materials/update';
        if (! $kyc && ! $payment && ! $query && ! $materials) {
            return $fallback;
        }
        if ($path === '/api/kyc/new' && $error->upstreamCode === -1004) {
            return '身份创建结果需要核实，请勿重复申请，请联系客服处理';
        }
        if ($error->upstreamCode === -1005) {
            if ($path === '/api/kyc/new') {
                return '暂时无法开通认证，请联系客服核实';
            }

            return $kyc || $materials ? '认证账户未激活或无法找到，请联系客服核实' : $fallback;
        }
        if ($path === '/api/kyc/query' && $error->upstreamCode === -1010) {
            return '认证账户未激活或无法找到，请联系客服核实';
        }
        if ($materials) {
            $message = match ($error->upstreamCode) {
                -1030 => '补件任务未被识别，请刷新或联系客服核实',
                -1031 => '补件关联交易需要核实，请联系客服处理',
                default => null,
            };
            if ($message !== null) {
                return $message;
            }
        }
        if ($payment) {
            $message = match ($error->upstreamCode) {
                -1040 => '当前支付方式不受支持，请联系客服核实',
                -1041 => '付款请求未获受理，请联系客服核实后再操作',
                default => null,
            };
            if ($message !== null) {
                return $message;
            }
        }

        return match ($error->upstreamCode) {
            -1000 => '汇款通道暂不可用，请稍后查询或联系客服',
            -1001 => '通道校验未通过，请联系客服处理',
            -1002 => '提交信息不完整或格式不正确，请核对后重试',
            -1003 => '本次请求暂未被受理，请联系客服核实',
            default => $fallback,
        };
    }
}
