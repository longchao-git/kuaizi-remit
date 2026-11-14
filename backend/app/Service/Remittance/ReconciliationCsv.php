<?php

namespace App\Service\Remittance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReconciliationCsv
{
    public const STATUSES = ['pending', 'paid', 'paid_out', 'failed', 'refunding', 'refunded', 'refund_failed'];

    /** 读取本地模板，金额始终以整数欧分表示。 */
    public function parse(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw new InvalidArgumentException('请上传 UTF-8 编码的 CSV 文件');
        }

        $lines = preg_split('/\r\n|\r|\n/', str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);
        $header = $this->columns(array_shift($lines));
        if ($header !== ['order_id', 'amount_eur'] && $header !== ['order_id', 'amount_eur', 'status']) {
            throw new InvalidArgumentException('请按模板提供 order_id,amount_eur，可选第三列 status');
        }

        $rows = [];
        foreach ($lines as $offset => $line) {
            if (trim($line) === '') {
                continue;
            }

            $number = $offset + 2;
            $columns = $this->columns($line);
            [$reference, $amount, $status] = array_pad($columns, 3, '');
            $reference = strtolower($reference);
            if (count($columns) !== count($header) || ! Str::isUuid($reference)
                || preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $amount, $parts) !== 1
                || ($status !== '' && ! in_array($status, self::STATUSES, true))) {
                throw new InvalidArgumentException("第 {$number} 行的订单号、金额或状态格式有误");
            }
            if (isset($rows[$reference])) {
                throw new InvalidArgumentException("第 {$number} 行的订单号重复");
            }
            if (count($rows) >= 5000) {
                throw new InvalidArgumentException('单批最多导入 5,000 条对账记录');
            }

            $rows[$reference] = [
                'order_reference' => $reference,
                'imported_minor' => (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0'),
                'imported_status' => $status === '' ? null : $status,
            ];
        }
        if ($rows === []) {
            throw new InvalidArgumentException('CSV 中没有可导入的订单');
        }

        ksort($rows);

        return array_values($rows);
    }

    private function columns(string $line): array
    {
        if (preg_match('/^\h*(?:"(?:[^"]|"")*"|[^",]*)(?:,\h*(?:"(?:[^"]|"")*"|[^",]*))*\h*$/D', $line) !== 1) {
            return [];
        }

        return array_map(static fn (?string $value) => trim($value ?? ''), str_getcsv($line, escape: ''));
    }
}
