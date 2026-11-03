<?php

declare(strict_types=1);

namespace App\Uuims\Support;

/**
 * Business error rendered by ApiExceptionMiddleware as {code, message, data}.
 * Codes: 401xx auth, 403xx permission, 404xx not found, 422xx validation, 500xx server.
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly int $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message, $errorCode);
    }

    public static function unauthorized(string $message = '未登录或登录已失效'): self
    {
        return new self(40101, $message, 401);
    }

    public static function forbidden(string $message = '无权限', int $code = 40301): self
    {
        return new self($code, $message, 403);
    }

    public static function notFound(string $message = '数据不存在或无权访问'): self
    {
        return new self(40401, $message, 404);
    }

    /** @param array<string, string> $errors field => message */
    public static function validation(array $errors): self
    {
        return new self(42201, (string) (array_values($errors)[0] ?? '参数错误'), 422, ['errors' => $errors]);
    }

    public static function conflict(string $message): self
    {
        return new self(42202, $message, 422);
    }
}
