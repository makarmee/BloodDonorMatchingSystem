<?php

namespace Database\Factories;

use App\Models\AppointmentStatusHistory;
use App\Models\DonorAppointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentStatusHistory>
 */
class AppointmentStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => DonorAppointment::factory(),
            'event_name' => 'Appointment Scheduled',
            'previous_status' => null,
            'new_status' => 'Pending',
            'actor_user_id' => null,
            'notes' => 'Initial donor booking created.',
            'occurred_at' => now(),
        ];
    }
}
