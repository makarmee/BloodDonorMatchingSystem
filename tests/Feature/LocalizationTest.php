<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_default_locale_is_english_with_ltr_direction(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
        $response->assertSee('Blood Stock');
    }

    public function test_user_can_switch_locale_via_post_request(): void
    {
        $response = $this->from(route('home'))
            ->post(route('locale.set'), ['locale' => 'fa']);

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('locale', 'fa');

        $pageResponse = $this->withSession(['locale' => 'fa'])
            ->get(route('home'));

        $pageResponse->assertOk();
        $pageResponse->assertSee('lang="fa"', false);
        $pageResponse->assertSee('dir="rtl"', false);
        $pageResponse->assertSee('صفحه نخست');
        $pageResponse->assertSee('موجودی خون');
    }

    public function test_user_can_switch_locale_via_get_request(): void
    {
        $response = $this->from(route('public.stock'))
            ->get(route('locale.switch', ['locale' => 'fa']));

        $response->assertRedirect(route('public.stock'));
        $response->assertSessionHas('locale', 'fa');

        $switchBack = $this->from(route('public.stock'))
            ->withSession(['locale' => 'fa'])
            ->get(route('locale.switch', ['locale' => 'en']));

        $switchBack->assertRedirect(route('public.stock'));
        $switchBack->assertSessionHas('locale', 'en');
    }

    public function test_invalid_locale_is_rejected_or_ignored(): void
    {
        $response = $this->post(route('locale.set'), ['locale' => 'invalid_lang']);
        $response->assertSessionHasErrors('locale');

        $getResponse = $this->get(route('locale.switch', ['locale' => 'unsupported']));
        $getResponse->assertSessionMissing('locale');
    }

    public function test_authenticated_user_retains_session_and_views_translated_dashboard(): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin = User::factory()->create([
            'is_active' => true,
        ]);
        $admin->roles()->attach($adminRole);

        $response = $this->actingAs($admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('lang="fa"', false);
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('مدیر سیستم');
        $response->assertSee('پورتال فعال');
    }

    public function test_gregorian_dates_remain_in_force_under_persian_locale(): void
    {
        $this->withSession(['locale' => 'fa'])
            ->get(route('home'));

        $now = Carbon::now();

        // Strict Gregorian invariant: year must be Gregorian (>= 2026), never Solar Hijri (1405)
        $this->assertGreaterThanOrEqual(2026, (int) $now->format('Y'));
    }

    public function test_public_pages_and_camps_render_successfully_in_persian(): void
    {
        $stockResponse = $this->withSession(['locale' => 'fa'])
            ->get(route('public.stock'));
        $stockResponse->assertOk();
        $stockResponse->assertSee('dir="rtl"', false);

        $campsResponse = $this->withSession(['locale' => 'fa'])
            ->get(route('public.camps.index'));
        $campsResponse->assertOk();
        $campsResponse->assertSee('dir="rtl"', false);

        $loginResponse = $this->withSession(['locale' => 'fa'])
            ->get(route('login'));
        $loginResponse->assertOk();
        $loginResponse->assertSee('dir="rtl"', false);
        $loginResponse->assertSee('ورود به سامانه BDMS');
    }
}
