<?php

use App\Domain\Identity\Enums\Role;
use App\Domain\System\Services\Settings;

test('defaults apply until a setting is saved', function () {
    expect(app(Settings::class)->get('receipt.language'))->toBe('si')
        ->and(app(Settings::class)->get('shop.name_si'))->toBe('සිටිසන්ස් ඇග්‍රෝ');
});

test('the Super Admin saves the shop profile in English and Sinhala', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'shop'), [
            'name_en' => 'CITIZENS Agro Centre',
            'name_si' => 'සිටිසන්ස් කෘෂි මධ්‍යස්ථානය',
            'address_en' => 'Main Street, Town',
            'address_si' => 'ප්‍රධාන වීදිය, නගරය',
            'phone' => '011 234 5678',
        ])
        ->assertRedirect(route('admin.settings.edit', 'shop'));

    expect(app(Settings::class)->get('shop.name_si'))->toBe('සිටිසන්ස් කෘෂි මධ්‍යස්ථානය')
        ->and(app(Settings::class)->get('shop.phone'))->toBe('011 234 5678');
});

test('checkbox and number settings are stored with the right types', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'tax'), ['registered' => '1', 'vat_number' => '123', 'default_rate' => '18'])
        ->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('tax.registered'))->toBeTrue()
        ->and(app(Settings::class)->get('tax.default_rate'))->toBe(18);
});

test('invalid settings are rejected', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->put(route('admin.settings.update', 'receipt'), ['language' => 'fr'])
        ->assertSessionHasErrors('language');
});

test('unknown settings groups return 404', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin))
        ->get(route('admin.settings.edit', 'nope'))
        ->assertNotFound();
});

test('only the Super Admin can change settings', function () {
    $this->actingAs(userWithRole(Role::Manager))
        ->get(route('admin.settings.edit', 'shop'))
        ->assertForbidden();
});
