<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\IpGeolocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

class LoginActivityWithGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_activity_can_include_geo(): void
    {
        $user = User::create([
            'name' => 'Geo User',
            'email' => 'geo@example.com',
            'password' => Hash::make('password123')
        ]);

        $geo = [
            'country' => 'Denmark',
            'country_code' => 'DK',
            'region' => 'Capital Region',
            'city' => 'Copenhagen',
        ];

        $this->mock(IpGeolocationService::class, function ($mock) use ($geo) {
            $mock->shouldReceive('getLocation')
                ->once()
                ->andReturn($geo);
        });

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password123');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('home', absolute: false));

        $this->assertAuthenticated();
        
        $activity = Activity::where('event', 'login')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertSame('Denmark', $activity->getExtraProperty('country'));
        $this->assertSame('Copenhagen', $activity->getExtraProperty('city'));
    }
}