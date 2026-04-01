<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_authenticate_returns_json_token_payload(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate', [
            'user' => $user->email,
            'password' => 'password',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'expires_in',
                'expires_at',
            ])
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_api_login_authenticate_returns_json_401_for_invalid_credentials(): void
    {
        $response = $this->post('/api/FortiaPrimeApi.Opensync/api/v2/login/authenticate', [
            'user' => 'nobody@example.test',
            'password' => 'wrong-password',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_api_enrolment_returns_json_401_without_redirect_even_when_accept_is_html(): void
    {
        $response = $this->post('/api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete', [], [
            'Accept' => 'text/html',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['message']);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->isRedirect());
    }

    public function test_api_admin_employees_returns_json_401_without_redirect_even_when_accept_is_html(): void
    {
        $response = $this->get('/api/admin/employees', [
            'Accept' => 'text/html',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['message']);

        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->isRedirect());
    }
}
