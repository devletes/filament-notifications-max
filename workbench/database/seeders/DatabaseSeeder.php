<?php

namespace Workbench\Database\Seeders;

use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Idempotent — workbench:build runs the seeder pass twice (once
        // via the yaml's `seeders:` list, once via the build pipeline)
        // and the unique email constraint would otherwise crash the
        // second pass.
        if (User::query()->exists()) {
            return;
        }

        // Three users covering the multi-panel access matrix. Same
        // password for all (`password`) so manual login is quick.
        $adminOnly = UserFactory::new()->create([
            'name' => 'Adam Admin',
            'email' => 'admin@example.test',
            'allowed_panels' => ['admin'],
        ]);

        $employeeOnly = UserFactory::new()->create([
            'name' => 'Emma Employee',
            'email' => 'employee@example.test',
            'allowed_panels' => ['employee'],
        ]);

        $hybrid = UserFactory::new()->create([
            'name' => 'Hank Hybrid',
            'email' => 'hybrid@example.test',
            'allowed_panels' => ['admin', 'employee'],
        ]);

        // Seed each user with one notification per type they can actually
        // receive — gives us a populated bell + notification center on
        // every panel for click-through testing.
        $this->seedNotificationsFor($adminOnly, ['announce.system']);
        $this->seedNotificationsFor($employeeOnly, ['task.due']);
        $this->seedNotificationsFor($hybrid, [
            'announce.system',
            'task.due',
            'shoutout.received',
        ]);
    }

    /**
     * @param  array<int, string>  $typeKeys
     */
    protected function seedNotificationsFor(User $user, array $typeKeys): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        foreach ($typeKeys as $typeKey) {
            $dispatcher->send($typeKey, $this->contextFor($typeKey), [$user]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function contextFor(string $typeKey): array
    {
        return match ($typeKey) {
            'announce.system' => [
                'headline' => 'Q4 update',
                'body' => 'Read the latest from leadership.',
                'announcement_id' => 1,
            ],
            'task.due' => [
                'task_title' => 'Submit timesheet',
                'due_at_relative' => 'in 2 hours',
                'task_id' => 42,
            ],
            'shoutout.received' => [
                'from_name' => 'Adam Admin',
                'message' => 'Great work on the launch!',
                'shoutout_id' => 7,
            ],
            default => [],
        };
    }
}
