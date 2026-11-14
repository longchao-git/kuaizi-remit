<?php

namespace App\Common;

use InvalidArgumentException;

/** @template T */
final readonly class Result
{
    /** @param T $data */
    private function __construct(
        private mixed $data,
        private string $msg,
        private int $code,
    ) {}

    /**
     * @template TValue
     *
     * @param  TValue  $data
     * @return self<TValue>
     */
    public static function success(mixed $data = null, string $msg = '操作成功'): self
    {
        return new self($data, $msg, 0);
    }

    /**
     * @template TValue
     *
     * @param  TValue  $data
     * @return self<TValue>
     */
    public static function fail(string $msg, int $code, mixed $data = null): self
    {
        if ($code === 0) {
            throw new InvalidArgumentException('失败结果的错误码必须为非零。');
        }

        return new self($data, $msg, $code);
    }

    public function isSuccess(): bool
    {
        return $this->code === 0;
    }

    public function isFail(): bool
    {
        return ! $this->isSuccess();
    }

    /** @return T */
    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMsg(): string
    {
        return $this->msg;
    }

    public function getCode(): int
    {
        return $this->code;
    }
}
