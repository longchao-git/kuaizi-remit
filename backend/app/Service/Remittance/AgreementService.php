<?php

namespace App\Service\Remittance;

use App\Model\Remittance\AgreementDraft;
use App\Model\Remittance\AgreementVersion;
use App\Repository\Remittance\AgreementDraftRepository;
use App\Repository\Remittance\AgreementRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class AgreementService
{
    /** 协议公开版本与后台草稿使用独立仓库，写操作共用事务。 */
    public function __construct(
        private AgreementRepository $agreements,
        private AgreementDraftRepository $drafts,
        private DatabaseManager $database,
    ) {}

    /** 读取指定协议的最新发布版。 */
    public function latest(string $kind): ?array
    {
        $version = $this->agreements->latest($kind);

        return $version ? $this->document($version) : null;
    }

    /** 后台分别读取已发布正文和待确认草稿，草稿不对 App 公开。 */
    public function editor(string $kind): array
    {
        $draft = $this->drafts->find($kind);

        return [
            'document' => $this->latest($kind),
            'draft' => $draft ? $this->draftDocument($draft) : null,
        ];
    }

    /** 保存独立草稿；首次并发插入或旧修订写入均拒绝，保留已保存正文。 */
    public function saveDraft(string $kind, string $body, int $staffId, ?string $revision): array
    {
        try {
            return $this->database->transaction(function () use ($kind, $body, $staffId, $revision): array {
                $draft = $this->checkedDraft($kind, $revision);
                $attributes = ['body' => $body, 'revision' => (string) Str::uuid(), 'updated_by' => $staffId];
                $saved = $draft
                    ? $this->drafts->update($draft, $attributes)
                    : $this->drafts->create($kind, $attributes);

                return $this->draftDocument($saved);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, '草稿已更新，请重新加载后再操作');
        }
    }

    /** 追加发布版本；新编辑页核对草稿修订，旧客户端发布不删除他人的草稿。 */
    public function publish(string $kind, string $body, int $staffId, ?string $revision = null, bool $checkDraft = false): array
    {
        return $this->database->transaction(function () use ($kind, $body, $staffId, $revision, $checkDraft): array {
            $draft = $checkDraft ? $this->checkedDraft($kind, $revision) : null;
            $version = $this->agreements->publish($kind, $body, $staffId);
            if ($draft !== null) {
                $this->drafts->remove($draft);
            }

            return $this->document($version);
        }, attempts: 3);
    }

    /** 锁定并核对客户端最后读到的修订号，空值仅表示尚无草稿。 */
    private function checkedDraft(string $kind, ?string $revision): ?AgreementDraft
    {
        $draft = $this->drafts->lock($kind);
        abort_if($draft?->revision !== $revision, 409, '草稿已更新，请重新加载后再操作');

        return $draft;
    }

    /** 草稿仅返回编辑所需字段，不公开保存人的账号信息。 */
    private function draftDocument(AgreementDraft $draft): array
    {
        return [
            'body' => $draft->body,
            'revision' => $draft->revision,
            'updated_at' => $draft->updated_at->toIso8601String(),
        ];
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
