<?php

namespace Workbench\App\Console;

use Illuminate\Console\Command;
use Workbench\App\Models\User;

class DumpSeedCommand extends Command
{
    protected $signature = 'workbench:dump-seed';

    protected $description = 'Print a summary of seeded users and their notifications.';

    public function handle(): int
    {
        foreach (User::all() as $user) {
            $this->line("{$user->email} | panels=" . json_encode($user->allowed_panels));

            foreach ($user->notifications()->get() as $n) {
                $action = $n->data['action'] ?? null;
                $url = $n->data['actions'][0]['url']
                    ?? $n->data['url']
                    ?? $n->data['action_url']
                    ?? '(none)';

                if (is_array($action)) {
                    $this->line(sprintf(
                        "  · %s/%s panels=%s preferred=%s",
                        $action['resource'] ?? '-',
                        $action['record_id'] ?? '-',
                        json_encode($action['panels'] ?? []),
                        $action['preferred_panel'] ?? '-',
                    ));
                } else {
                    $this->line('  · (no structured action)');
                }

                $this->line("    url={$url}");
            }
        }

        return self::SUCCESS;
    }
}
