<?php

namespace App\Service\Remittance;

use App\Model\Remittance\AgreementVersion;
use App\Repository\Remittance\AgreementRepository;

final readonly class AgreementService
{
    /** 复用协议版本仓库。 */
    public function __construct(private AgreementRepository $agreements) {}

    /** 读取指定协议的最新发布版。 */
    public function latest(string $kind): ?array
    {
        $version = $this->agreements->latest($kind);

        return $version ? $this->document($version) : null;
    }

    /** 追加发布版本并保留操作人，不覆盖历史正文。 */
    public function publish(string $kind, string $body, int $staffId): array
    {
        return $this->document($this->agreements->publish($kind, $body, $staffId));
    }

    /** 公共响应仅包含 App 阅读需要的信息。 */
    private function document(AgreementVersion $version): array
    {
        return [
            'version' => (string) $version->id,
            'body' => $version->body,
            'published_at' => $version->published_at->toIso8601String(),
        ];
    }
}
