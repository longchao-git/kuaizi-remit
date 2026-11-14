<?php

use App\Common\ResponseResult;
use App\Common\Result;
use App\Constants\Common\ErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn (Request $request) => $request->is('api', 'api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api', 'api/*') || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $result = $exception instanceof ValidationException
                ? Result::fail('请求参数不正确', ErrorCode::INVALID_PARAMS, $exception->errors())
                : Result::fail(match ($status) {
                    401 => '请先登录',
                    403 => '没有操作权限',
                    404 => '资源不存在',
                    405 => '请求方法不支持',
                    429 => '请求过于频繁',
                    default => $status >= 500 ? '系统异常' : '请求失败',
                }, $status);

            $json = ResponseResult::json($result, $status);
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->remove('Content-Length');

            return $response->setContent($json->getContent());
        });
    })->create();
