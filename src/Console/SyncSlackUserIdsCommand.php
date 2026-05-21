<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console;

use Devletes\NotificationsMax\Services\SlackUserIdResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Backfill `slack_user_id` on users whose email matches a Slack workspace
 * member. The auto-resolve listener (registered by the package's service
 * provider) handles freshly-created users automatically — this command
 * exists for the install-time backfill and for hosts that bulk-import
 * users without firing Eloquent events.
 *
 * Usage:
 *
 *   php artisan notifications-max:sync-slack-user-ids
 *     # Resolves every user with a non-empty email and an empty slack_user_id
 *
 *   php artisan notifications-max:sync-slack-user-ids --force
 *     # Re-resolves every user with a non-empty email, including those
 *     # whose slack_user_id is already set. Useful after a bulk email-
 *     # address change in either system.
 *
 *   php artisan notifications-max:sync-slack-user-ids --model=App\Models\Person
 *     # Override the auto-detected user model (default:
 *     # config('auth.providers.users.model')). Use when the host has
 *     # multiple notifiable models with their own slack_user_id columns.
 *
 * Rate limiting:
 *   Slack's `users.lookupByEmail` is Tier 2 (~20 calls/minute per
 *   workspace). The command waits 3.1 seconds between calls — enough
 *   margin that a single worker won't trip the limit even if other
 *   processes share the token. For very large user lists, split the
 *   work across workspace/token boundaries rather than running multiple
 *   workers against the same token concurrently.
 */
class SyncSlackUserIdsCommand extends Command
{
    protected $signature = 'notifications-max:sync-slack-user-ids
                            {--model= : Fully-qualified model class to sync (default: auth user model)}
                            {--force : Re-resolve users that already have a slack_user_id}';

    protected $description = 'Resolve and store Slack user IDs by looking up the model\'s email against Slack.';

    public function handle(SlackUserIdResolver $resolver): int
    {
        $modelClass = (string) ($this->option('model') ?: config('auth.providers.users.model'));

        if (! class_exists($modelClass)) {
            $this->components->error("Model class [{$modelClass}] not found.");

            return self::FAILURE;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->components->error("[{$modelClass}] is not an Eloquent model.");

            return self::FAILURE;
        }

        /** @var class-string<Model> $modelClass */
        $instance = new $modelClass;
        $table = $instance->getTable();

        if (! $this->columnExists($table, 'slack_user_id')) {
            $this->components->error("Column `{$table}.slack_user_id` does not exist. Run `php artisan migrate` first.");

            return self::FAILURE;
        }

        if (! $this->columnExists($table, 'email')) {
            $this->components->error("Column `{$table}.email` does not exist. Cannot look up by email.");

            return self::FAILURE;
        }

        $query = $modelClass::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if (! $this->option('force')) {
            $query->where(function ($q): void {
                $q->whereNull('slack_user_id')->orWhere('slack_user_id', '');
            });
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->components->info('Nothing to resolve — every user with an email already has a slack_user_id.');

            return self::SUCCESS;
        }

        $this->components->info("Resolving Slack IDs for {$total} user(s) (Slack rate limit pacing — ~3s/call).");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $matched = 0;
        $notFound = 0;
        $errored = 0;
        $first = true;

        $query->lazy(100)->each(function (Model $model) use ($resolver, $bar, &$matched, &$notFound, &$errored, &$first): void {
            // Sleep between calls (not before the first) to stay under
            // Slack's Tier 2 rate limit. 3.1s gives ~19/min, safely
            // under the documented 20/min cap.
            if (! $first) {
                usleep(3_100_000);
            }
            $first = false;

            try {
                $id = $resolver->resolve((string) $model->getAttribute('email'));

                if ($id !== null) {
                    $model->forceFill(['slack_user_id' => $id])->saveQuietly();
                    $matched++;
                } else {
                    $notFound++;
                }
            } catch (Throwable $e) {
                $errored++;
                report($e);
            }

            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('<fg=green>Matched</>', (string) $matched);
        $this->components->twoColumnDetail('<fg=yellow>Not in workspace</>', (string) $notFound);

        if ($errored > 0) {
            $this->components->twoColumnDetail('<fg=red>Errored</>', (string) $errored);
            $this->components->warn('Check your logs (errors were reported via report()) — first failure often means a missing scope or invalid token.');
        }

        return $errored > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Wrapper for Schema::hasColumn that ignores DB-not-reachable errors
     * (returns false). Avoids blowing up with a confusing stack trace if
     * the operator runs the command before .env DB config is set.
     */
    protected function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }
}
