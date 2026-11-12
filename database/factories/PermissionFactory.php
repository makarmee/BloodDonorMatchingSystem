<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $module = fake()->randomElement(['inventory', 'donors', 'screening', 'requests', 'hospitals', 'camps']);
        $suffix = fake()->unique()->numerify('#####');

        return [
            'name' => 'Permission '.$suffix,
            'slug' => $module.'.action_'.$suffix,
            'module' => $module,
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Scope the generated permission to a specific BDMS module.
     */
    public function inModule(string $module): static
    {
        return $this->state(function (array $attributes) use ($module): array {
            $suffix = fake()->unique()->numerify('#####');

            return [
                'module' => $module,
                'slug' => $module.'.action_'.$suffix,
            ];
        });
    }
}
