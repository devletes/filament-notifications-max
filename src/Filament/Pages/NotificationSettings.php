<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Pages;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Pages\Concerns\BuildsNotificationPrefsLayout;
use Devletes\NotificationsMax\Models\NotificationTypeOverride;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnitEnum;
use BackedEnum;

/**
 * Admin-facing notification settings page.
 *
 * Mirrors the user-preferences page in layout (categories → groups →
 * inline toggles), but the toggles control admin allowance — the upper
 * bound on what users can enable for each (tenant, type). When admin
 * disables `email` for a type, no user can subsequently enable it.
 *
 * Mandatory types are shown read-only with a "Required" indicator —
 * they always fire on every channel declared at config level, regardless
 * of admin allowance (compliance can't be silenced by accident).
 *
 * The page only registers on a panel when the host opts in via
 * `NotificationsMaxPlugin::make()->notificationSettingsPage()`. Access is
 * gated through `canAccess()` on the configured Spatie permission so
 * non-admin users hitting the URL get a 403.
 *
 * The "Manage Content" link beside each row is rendered only when the
 * package is in `database` content mode — config mode means content
 * lives in the host's PHP files and there's nothing to edit through the
 * UI. Modal wiring lives in {@see NotificationSettings::manageContentAction()}
 * (Pass D).
 */
class NotificationSettings extends Page implements HasForms
{
    use BuildsNotificationPrefsLayout;
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notification settings';

    protected static ?string $slug = 'notification-settings';

    protected string $view = 'filament-notifications-max::pages.notification-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = filament()->auth()->user();

        if (! $user instanceof Authenticatable) {
            return false;
        }

        $permission = static::permissionName();

        // Permission gate disabled — the plugin's per-panel opt-in
        // (`->notificationSettingsPage()`) is the only access control.
        // Useful for apps without Spatie Permission or with a different
        // authorization layer.
        if ($permission === null) {
            return true;
        }

        return method_exists($user, 'can') ? (bool) $user->can($permission) : false;
    }

    protected static function permissionName(): ?string
    {
        $name = config('notifications-max.notification_settings.permission');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function mount(): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $tenantId = app(TenantResolver::class)->currentId();

        $settings = [];

        foreach ($registry->all() as $key => $type) {
            $safe = $this->safeKey($key);
            $allowed = $this->loadAllowedChannels($tenantId, $key, $type);

            // Iterate the type's full ceiling so admins see every channel
            // that COULD ever be allowed for this type, with toggles in
            // the correct on/off state for whichever ones are currently
            // allowed.
            foreach ($type->allowedChannels as $channel) {
                $settings[$safe][$channel] = $type->mandatory
                    ? true
                    : in_array($channel, $allowed, true);
            }
        }

        $this->form->fill(['settings' => $settings]);
    }

    public function form(Schema $schema): Schema
    {
        $registry = app(NotificationTypeRegistry::class);
        $types = collect($registry->all());

        $categories = $types
            ->groupBy(fn (NotificationType $t) => $t->category)
            ->sortKeys();

        $components = [];

        // Read-only callout. Sits above the tabs when the package is in
        // config mode so the page communicates honestly: toggles render
        // for visibility but can't be edited — content lives in the host
        // app's config files. Hidden in database mode (the page is fully
        // editable then).
        if ($this->isReadOnly()) {
            // HtmlString lets the callout render an anchor inline. URL is
            // config-driven so the package's docs can move without a code
            // change — hosts can also point this at their own internal
            // runbook if they prefer.
            $docsUrl = (string) config(
                'notifications-max.docs.database_mode_url',
                'https://filament.devletes.com/notifications-max#database-mode',
            );

            $components[] = Callout::make(new HtmlString(
                'Notification settings are in preview mode because they are managed in the config file. '
                .'To be able to manage the settings here, enable '
                .'<a href="'.e($docsUrl).'" target="_blank" rel="noopener" class="underline">database mode</a>.'
            ))
                ->color('info')
                ->icon('heroicon-o-information-circle');
        }

        // One Section per category, stacked. Replaced tabs after approval
        // types were collapsed — the page is short enough to read end-to-
        // end without nav switching, which is friendlier for an admin
        // doing a sweep.
        foreach ($categories as $category => $categoryTypes) {
            $components[] = Section::make(Str::headline((string) $category))
                ->schema($this->buildCategoryComponents($categoryTypes))
                ->collapsible()
                ->collapsed(false);
        }

        return $schema
            ->components($components)
            ->statePath('data');
    }

    /**
     * The page is read-only whenever the content source is config — there
     * is no DB row to write to, so persisting changes from the form would
     * be confusing. The form still renders so admins can see the current
     * state at a glance, but every toggle is disabled and the save button
     * is hidden by the view.
     */
    public function isReadOnly(): bool
    {
        return ! app(NotificationContentResolver::class)->shouldUseDatabase();
    }

    public function save(): void
    {
        // Defensive: read-only mode hides the save button in the view, so
        // this branch is only reachable via direct Livewire dispatch. Bail
        // silently rather than persisting nonsense.
        if ($this->isReadOnly()) {
            return;
        }

        $registry = app(NotificationTypeRegistry::class);
        $tenantId = app(TenantResolver::class)->currentId();

        $submitted = $this->form->getState()['settings'] ?? [];

        foreach ($registry->all() as $key => $type) {
            // Mandatory types ignore admin allowance — they always fire on
            // their config-level channel set. Skip persisting overrides for
            // them so a stale row never accidentally narrows delivery.
            if ($type->mandatory) {
                continue;
            }

            $safe = $this->safeKey($key);
            $row = $submitted[$safe] ?? [];

            // Build the allowance list from toggles that are ON, intersected
            // with the type's config-level ceiling so a stale form value
            // can't expose an unsupported channel.
            $allowed = array_values(array_filter(
                $type->allowedChannels,
                fn (string $channel): bool => (bool) ($row[$channel] ?? false),
            ));

            $override = NotificationTypeOverride::query()
                ->firstOrNew(['tenant_id' => $tenantId, 'type_key' => $key]);

            $override->tenant_id = $tenantId;
            $override->type_key = $key;
            $override->allowed_channels = $allowed;
            $override->save();
        }

        // Drop the resolver's in-request cache so the new allowance takes
        // effect on subsequent dispatches in the same request (e.g. tests).
        app(NotificationContentResolver::class)->flushCache();

        Notification::make()
            ->title('Notification settings saved')
            ->success()
            ->send();
    }

    // ------------------------------------------------------------------
    // BuildsNotificationPrefsLayout abstracts
    // ------------------------------------------------------------------

    protected function statePathPrefix(): string
    {
        return 'settings';
    }

    /**
     * Admin sees the type's full ceiling — every channel the type could
     * ever fire on. Toggle state determines which subset is currently
     * allowed.
     *
     * @return array<int, string>
     */
    protected function channelsForType(NotificationType $type): array
    {
        return $type->allowedChannels;
    }

    protected function isToggleDisabled(NotificationType $type): bool
    {
        // Disabled when the page is read-only (config mode) OR when the
        // type is mandatory. Both render visibly but un-editable so
        // admins can see what's currently in effect.
        return $this->isReadOnly() || $type->mandatory;
    }

    /**
     * Inject a "Manage Content" link beside the channel toggles when the
     * package is in DB content mode. Hidden in config mode (no override
     * row to write to). Modal action wiring lives in
     * {@see manageContentAction()} — Pass D.
     *
     * @return array<int, Component>
     */
    protected function extraRowComponents(NotificationType $type): array
    {
        if (! app(NotificationContentResolver::class)->shouldUseDatabase()) {
            return [];
        }

        if ($type->mandatory) {
            // Content is editable on mandatory types too (admins can
            // customise the wording even when channel allowance is fixed),
            // so no early return — kept here as documentation that the
            // mandatory flag does not gate the modal.
        }

        $safe = $this->safeKey($type->key);

        return [
            \Filament\Schemas\Components\Actions::make([
                $this->manageContentAction($type, $safe),
            ])->columnSpan(1),
        ];
    }

    /**
     * Build the per-type "Manage content" action. Modal form is generated
     * dynamically from each channel's `content_fields` declaration so any
     * registered channel — including ones the host adds later — gets the
     * right editor (text input, textarea, rich editor, template select)
     * without modal code changes.
     *
     * On submit: writes the channel-keyed payload to
     * `notification_type_overrides.channel_content` for the current
     * (tenant, type). The resolver picks up edits on the next dispatch.
     */
    protected function manageContentAction(NotificationType $type, string $safe): Action
    {
        $resolver = app(NotificationContentResolver::class);
        $channels = $resolver->allChannels();

        return Action::make("manageContent.{$safe}")
            ->label('Manage content')
            ->icon('heroicon-m-pencil-square')
            ->color('gray')
            ->link()
            ->modalHeading(fn (): string => "Manage content — {$type->label}")
            ->modalDescription($this->placeholderHintFor($type))
            ->modalWidth('4xl')
            ->fillForm(fn (): array => $this->loadContentForForm($type))
            ->schema($this->buildModalSchema($type, $channels))
            ->action(function (array $data) use ($type): void {
                $this->saveContent($type, $data);

                Notification::make()
                    ->title('Content saved')
                    ->body("Content for `{$type->label}` updated.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Build the modal's component tree by iterating registered channels
     * and rendering the right editor per `content_fields` entry.
     *
     * @param  array<string, array<string, mixed>>  $channels
     * @return array<int, Component>
     */
    protected function buildModalSchema(NotificationType $type, array $channels): array
    {
        $sections = [];

        foreach ($channels as $channelKey => $channelDef) {
            $fields = $channelDef['content_fields'] ?? [];

            if ($fields === []) {
                continue;
            }

            $label = $channelDef['label'] ?? Str::headline((string) $channelKey);

            $sections[] = Section::make($label)
                ->schema(array_values(array_map(
                    fn (string $fieldType, string $fieldName) => $this->fieldComponent($channelKey, $fieldName, $fieldType),
                    $fields,
                    array_keys($fields),
                )));
        }

        return $sections;
    }

    /**
     * One Filament form component per content field, dispatching on the
     * field's declared editor type. New editor types added in future
     * (e.g. `markdown`, `code`) slot in here without touching anything else.
     */
    protected function fieldComponent(string $channel, string $field, string $fieldType): Component
    {
        $statePath = "{$channel}.{$field}";
        $label = Str::headline($field);

        return match ($fieldType) {
            'string' => TextInput::make($statePath)
                ->label($label)
                ->maxLength(200),

            'text' => Textarea::make($statePath)
                ->label($label)
                ->rows(4),

            'rich-text' => RichEditor::make($statePath)
                ->label($label)
                ->columnSpanFull(),

            'template-select' => Select::make($statePath)
                ->label($label)
                ->options(fn (): array => $this->emailTemplateOptions())
                ->placeholder('Default template')
                ->searchable(),

            default => TextInput::make($statePath)
                ->label($label),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function emailTemplateOptions(): array
    {
        $templates = config('notifications-max.email_templates', []);

        if (! is_array($templates)) {
            return [];
        }

        $options = [];

        foreach (array_keys($templates) as $key) {
            $options[(string) $key] = Str::headline((string) $key);
        }

        return $options;
    }

    /**
     * Hint that lists the placeholders the type's templates accept, so
     * admins know what `{tokens}` they can drop into the body. Built by
     * scraping the type's config-level title + body for `{name}`-style
     * tokens — no need for an explicit declaration.
     */
    protected function placeholderHintFor(NotificationType $type): string
    {
        $sources = [$type->title, $type->body];

        foreach ($type->content as $channelContent) {
            if (is_array($channelContent)) {
                foreach ($channelContent as $value) {
                    if (is_string($value)) {
                        $sources[] = $value;
                    }
                }
            }
        }

        $tokens = [];

        foreach ($sources as $source) {
            if (preg_match_all('/\{([a-zA-Z0-9_\.]+)\}/', (string) $source, $matches)) {
                foreach ($matches[1] as $name) {
                    $tokens[$name] = true;
                }
            }
        }

        if ($tokens === []) {
            return 'No placeholders detected for this type. Edit the content directly.';
        }

        $list = implode(', ', array_map(fn ($k) => '{'.$k.'}', array_keys($tokens)));

        return "Available placeholders: {$list}";
    }

    /**
     * Hydrate the modal form with the current override (or seeded defaults)
     * for this type. Returns a flat shape keyed by `{channel}.{field}` so
     * Filament's nested-state resolution writes back to the same paths the
     * field components declare.
     *
     * @return array<string, mixed>
     */
    protected function loadContentForForm(NotificationType $type): array
    {
        $tenantId = app(TenantResolver::class)->currentId();
        $resolver = app(NotificationContentResolver::class);

        $form = [];

        foreach ($resolver->allChannels() as $channelKey => $channelDef) {
            $fields = $channelDef['content_fields'] ?? [];

            if ($fields === []) {
                continue;
            }

            $resolved = $resolver->contentFor($type->key, (string) $channelKey, $tenantId);

            $form[(string) $channelKey] = [];

            foreach (array_keys($fields) as $field) {
                $form[(string) $channelKey][$field] = $resolved[$field] ?? null;
            }
        }

        return $form;
    }

    /**
     * Persist submitted modal data to the (tenant, type) override row.
     * Stored as a channel-keyed JSON object so the resolver can read it
     * with no further reshaping.
     *
     * @param  array<string, array<string, mixed>>  $data
     */
    protected function saveContent(NotificationType $type, array $data): void
    {
        if (! app(NotificationContentResolver::class)->shouldUseDatabase()) {
            return;
        }

        $tenantId = app(TenantResolver::class)->currentId();

        $override = NotificationTypeOverride::query()
            ->firstOrNew(['tenant_id' => $tenantId, 'type_key' => $type->key]);

        $override->tenant_id = $tenantId;
        $override->type_key = $type->key;
        $override->channel_content = $data;
        $override->save();

        app(NotificationContentResolver::class)->flushCache();
    }

    /**
     * Override allowed_channels lookup for the page's mount(). Mirrors
     * NotificationContentResolver::allowedChannelsFor() but without the
     * mandatory short-circuit — we always want the row's stored value
     * here so admins see what's persisted, not the runtime-resolved
     * value.
     *
     * @return array<int, string>
     */
    protected function loadAllowedChannels(?int $tenantId, string $typeKey, NotificationType $type): array
    {
        $override = NotificationTypeOverride::lookup($tenantId, $typeKey);

        if ($override === null || $override->allowed_channels === null) {
            return $type->allowedChannels;
        }

        return $override->allowed_channels;
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];

        $group = static::getNavigationGroup();

        if ($group instanceof UnitEnum) {
            $group = $group->name;
        }

        if (is_string($group) && $group !== '') {
            $breadcrumbs[] = $group;
        }

        $breadcrumbs[] = static::getNavigationLabel() ?: (static::$title ?? 'Notification settings');

        return $breadcrumbs;
    }
}
