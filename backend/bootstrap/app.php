<?php

use App\Common\ResponseResult;
use App\Common\Result;
use App\Constants\Common\ErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
        then: function (): void {
            // 旧包和已登记通知地址继续使用同一套业务与权限，名称与规范路由分开。
            Route::middleware('api')->prefix('api')->name('legacy.')->group(__DIR__.'/../routes/api.php');
            Broadcast::routes(['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']]);
        },
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['api', 'auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(static fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn () => true,
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() < 400) {
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
