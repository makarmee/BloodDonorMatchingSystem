<?php

namespace Tests\Feature;

use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    public function test_inventory_page_renders_all_eight_blood_groups_statistics_and_sections(): void
    {
        $response = $this->get(route('inventory.index'));

        $response->assertOk();
        $response->assertViewIs('inventory.index');
        $response->assertViewHas('bloodGroups');
        $response->assertViewHas('summary');
        $response->assertViewHas('history');

        $response->assertSee('Blood Inventory Management');
        $response->assertSee('Total Available Units');
        $response->assertSee('Total Blood Groups');
        $response->assertSee('Low Stock Groups');
        $response->assertSee('Critical Stock Groups');
        $response->assertSee('Detailed Blood Inventory');
        $response->assertSee('Recent Inventory History');
        $response->assertSee('Adjust Blood Inventory');

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $group) {
            $response->assertSee($group);
        }

        $response->assertSee('Normal');
        $response->assertSee('Low Stock');
        $response->assertSee('Critical');
    }

    public function test_inventory_page_supports_hospital_portal_preview_and_ignores_invalid_role(): void
    {
        $this->get(route('inventory.index', ['role' => 'hospital']))
            ->assertOk()
            ->assertViewHas('activeRole', 'hospital')
            ->assertSee('Regional Stock');

        $this->get(route('inventory.index', ['role' => 'donor']))
            ->assertOk()
            ->assertViewHas('activeRole', 'staff');
    }
}
