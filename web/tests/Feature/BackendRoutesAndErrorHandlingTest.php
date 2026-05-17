<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BackendRoutesAndErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach ([
            'SHOPIFY_STORE_DOMAIN',
            'SHOPIFY_ACCESS_TOKEN',
            'SHOPIFY_API_VERSION',
            'SHOPIFY_ORDERS_LOAD_EXTERNAL_DATA',
            'VITE_APP_STATUS',
        ] as $name) {
            $this->setEnv($name, null);
        }

        parent::tearDown();
    }

    public function test_protected_order_routes_redirect_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/api/shopify/orders')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_orders_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertViewIs('orders');
    }

    public function test_shopify_orders_endpoint_returns_orders_without_external_integrations(): void
    {
        $this->useShopifyTestConfig();
        Http::fakeSequence($this->shopifyGraphqlUrl())
            ->push($this->emptyOrdersResponse());

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/shopify/orders?view=ready-to-pack');

        $response
            ->assertOk()
            ->assertJsonPath('orders', [])
            ->assertJsonPath('integration_status.shopify.status', 'loaded')
            ->assertJsonPath('integration_status.sources.business_central.status', 'disabled')
            ->assertJsonPath('integration_status.sources.webshipper.status', 'disabled');
    }

    public function test_shopify_order_errors_are_returned_as_user_friendly_messages(): void
    {
        $this->setEnv('SHOPIFY_STORE_DOMAIN', null);
        $this->setEnv('SHOPIFY_ACCESS_TOKEN', null);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/shopify/orders?view=ready-to-pack');

        $response
            ->assertStatus(500)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'Order data could not be loaded right now. Please refresh and try again.');

        $this->assertStringNotContainsString('SHOPIFY_ACCESS_TOKEN', $response->json('error'));
        $this->assertStringNotContainsString('Missing Shopify config', $response->json('error'));
    }

    public function test_fulfillment_permission_error_keeps_orders_visible_and_logs_technical_details(): void
    {
        $this->useShopifyTestConfig();
        Log::spy();

        Http::fakeSequence($this->shopifyGraphqlUrl())
            ->push([
                'errors' => [
                    ['message' => 'Access denied for fulfillmentOrders field.'],
                ],
            ])
            ->push($this->emptyOrdersResponse());

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/shopify/orders?view=ready-to-pack');

        $response
            ->assertOk()
            ->assertJsonPath('orders', [])
            ->assertJsonPath('integration_status.shopify.status', 'loaded')
            ->assertJsonPath('integration_status.sources.shopify_fulfillment.status', 'failed')
            ->assertJsonPath(
                'integration_status.sources.shopify_fulfillment.message',
                'The app is missing Shopify permissions for fulfillment data. Orders are still shown.'
            );

        Log::shouldHaveReceived('warning')
            ->with(Mockery::on(
                fn(string $message): bool => str_contains($message, 'Shopify fulfillment data fallback:')
                    && str_contains($message, 'Access denied for fulfillmentOrders field')
            ))
            ->once();
    }

    public function test_shopify_fulfillment_permission_mutation_uses_friendly_error_message(): void
    {
        $this->useShopifyTestConfig();
        Http::fakeSequence($this->shopifyGraphqlUrl())
            ->push([
                'errors' => [
                    ['message' => 'Access denied for fulfillment order mutation.'],
                ],
            ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/shopify/orders/ready-for-pickup', [
                'fulfillment_order_id' => 'gid://shopify/FulfillmentOrder/123',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath(
                'error',
                'The app is missing Shopify permissions for fulfillment data. '
                    . 'Reinstall the app with the updated permissions, then try again.'
            );

        $this->assertStringNotContainsString('GraphQL error', $response->json('error'));
        $this->assertStringNotContainsString('Access denied', $response->json('error'));
    }

    public function test_webshipper_label_endpoint_is_disabled_in_test_mode(): void
    {
        $this->setEnv('VITE_APP_STATUS', 'Test');
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/webshipper/label?orderId=123');

        $response
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath(
                'error',
                'App is in Test mode; label creation is disabled. Set VITE_APP_STATUS=Production to enable.'
            );
    }

    public function test_ui_actions_are_logged_to_activity_log(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($user)
            ->postJson('/api/log-button-click', [
                'button' => 'view-in-shopify-product-pick',
                'order_id' => 1019,
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'ui',
            'event' => 'button_clicked',
            'description' => 'Button clicked: view-in-shopify-product-pick',
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);
    }

    public function test_telescope_gate_allows_only_allowlisted_users(): void
    {
        $allowed = User::factory()->create(['email' => 'sofia@example.com']);
        $blocked = User::factory()->create(['email' => 'blocked@example.com']);

        $this->assertTrue(Gate::forUser($allowed)->allows('viewTelescope'));
        $this->assertFalse(Gate::forUser($blocked)->allows('viewTelescope'));
    }

    private function useShopifyTestConfig(): void
    {
        $this->setEnv('SHOPIFY_STORE_DOMAIN', 'test-shop.myshopify.com');
        $this->setEnv('SHOPIFY_ACCESS_TOKEN', 'test-token');
        $this->setEnv('SHOPIFY_API_VERSION', '2025-10');
        $this->setEnv('SHOPIFY_ORDERS_LOAD_EXTERNAL_DATA', 'false');
    }

    private function shopifyGraphqlUrl(): string
    {
        return 'https://test-shop.myshopify.com/admin/api/2025-10/graphql.json';
    }

    private function emptyOrdersResponse(): array
    {
        return [
            'data' => [
                'orders' => [
                    'edges' => [],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => null,
                    ],
                ],
            ],
        ];
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
