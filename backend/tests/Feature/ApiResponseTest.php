<?php

namespace Tests\Feature;

use App\Constants\Common\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use stdClass;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_health_uses_the_project_response_format(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertExactJson(['code' => 0, 'msg' => '操作成功', 'data' => ['status' => 'ok']]);
    }

    public function test_unknown_api_route_returns_an_empty_object_and_keeps_its_http_status(): void
    {
        $response = $this->get('/api/missing')->assertNotFound();

        $response->assertHeader('Content-Type', 'application/json');
        $payload = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertSame(404, $payload->code);
        $this->assertSame('资源不存在', $payload->msg);
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertSame([], get_object_vars($payload->data));
    }

    public function test_validation_failure_uses_the_business_error_code_and_field_errors(): void
    {
        Route::post('/api/test-validation', function (Request $request) {
            $request->validate(['name' => 'required']);
        });

        $this->postJson('/api/test-validation')
            ->assertUnprocessable()
            ->assertJsonPath('code', ErrorCode::INVALID_PARAMS)
            ->assertJsonStructure(['code', 'msg', 'data' => ['name']]);
    }

    public function test_method_not_allowed_keeps_its_allow_header(): void
    {
        $this->postJson('/api/health')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonPath('code', 405);
    }
}
