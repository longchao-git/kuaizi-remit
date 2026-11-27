<?php

namespace App\Service\Remittance;

use App\Service\Remittance\Contract\RemittanceChannel;
use App\Service\Remittance\Contract\RemittanceCheckoutTerms;
use App\Service\Remittance\Contract\RemittanceRateLock;
use App\Service\Remittance\Yaband\Contract\YabandCheckoutTerms;
use App\Service\Remittance\Yaband\Contract\YabandRateLock;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;

/** 从服务器配置解析渠道，已有业务只能使用创建时保存的渠道。 */
final class RemittanceChannels
{
    public const LOCAL = 'local';

    /** 只在本地或单测的隔离 SQLite 库开放模拟资金流程。 */
    public function activeScope(): string
    {
        return match (config('remit.channel')) {
            'local' => $this->localScope(),
            'yaband' => $this->yabandScope(),
            default => throw new YabandException('汇款渠道配置无效。'),
        };
    }

    /** 历史业务按成功迁移时的雅本地址及商户归属隔离。 */
    public function yabandScope(): string
    {
        $url = config('yaband_remit.base_url');
        $mid = config('yaband_remit.mid');
        if (! is_string($url) || trim($url) === '' || ! is_string($mid) || trim($mid) === '') {
            return 'yaband:unconfigured';
        }

        return 'yaband:'.hash('sha256', config('yaband_remit.environment').'|'.rtrim($url, '/').'|'.$mid);
    }

    /** 兼容历史本地记录，正式交易仍要求渠道范围与有效配置一致。 */
    public function storedScope(?string $scope): string
    {
        if ($scope !== null) {
            return $scope;
        }

        // 旧数据统一由迁移固定；测试直接插入的历史夹具保留兼容。
        return app()->runningUnitTests() ? $this->yabandScope() : 'yaband:unassigned';
    }

    /** 模拟模式拒绝任何真实出网调用，真实地址或商户变化后旧交易明确等待原配置。 */
    public function resolve(string $scope): RemittanceChannel
    {
        if ($scope === self::LOCAL) {
            $this->localScope();

            return app(LocalRemittanceChannel::class);
        }
        if ($this->activeScope() === self::LOCAL || $scope !== $this->yabandScope() || ! app()->bound(YabandRemitSigner::class)) {
            throw new YabandException('原汇款渠道暂不可用，请核对配置后再试。');
        }

        return app(YabandRemitClient::class);
    }

    /** 真实模式保留本人核验与成交适配器的既有开通门槛。 */
    public function checkoutTerms(string $scope): ?RemittanceCheckoutTerms
    {
        if ($scope === self::LOCAL) {
            $this->localScope();

            return app(LocalRemittanceChannel::class);
        }

        return app()->bound(YabandCheckoutTerms::class) ? app(YabandCheckoutTerms::class) : null;
    }

    /** 保留雅本的成交适配器门槛，模拟渠道提供独立的测试报价。 */
    public function rateLock(string $scope): ?RemittanceRateLock
    {
        if ($scope === self::LOCAL) {
            $this->localScope();

            return app(LocalRemittanceChannel::class);
        }

        return app()->bound(YabandRateLock::class) ? app(YabandRateLock::class) : null;
    }

    private function localScope(): string
    {
        if ((! app()->isLocal() && ! app()->runningUnitTests()) || config('database.default') !== 'sqlite') {
            throw new YabandException('模拟渠道只允许本地隔离数据库使用。');
        }

        return self::LOCAL;
    }
}
