{{--
    Override of filament/notifications `database-notifications.blade.php` (v5.5.0).

    Deltas from upstream:
      - Adds a "View all" link in the header pointing at our NotificationCenter
        page, rendered only when that page is registered in the current panel.
      - Hides the stock "Clear" action whenever the NotificationCenter page is
        registered — deletion lives in the center to keep a single destructive
        surface. When the center isn't enabled, stock behaviour is preserved.
      - Normalizes each notification's action-URL scheme at render time
        (ActionUrlSchemeNormalizer): legacy rows baked `http://` URLs whose
        wire:navigate fetch browsers hard-block as mixed content under SPA
        mode. See the normalizer's class docblock for the full rationale.
      - Closes the slide-over when a navigation link inside it is clicked
        (delegated to `a[href]` only, so mark-as-read/archive buttons keep it
        open). Under SPA mode the modal survives the wire:navigate body swap,
        so without this the app navigates BEHIND the open panel.

    The rest of the template is verbatim from upstream. If a future Filament
    release changes this file, re-sync the non-header portion and keep the
    four deltas above intact. The namespace prepend is registered in
    NotificationsMaxServiceProvider::packageBooted().
--}}
@php
    use Devletes\NotificationsMax\Support\ActionUrlSchemeNormalizer;
    use Filament\Support\Enums\Alignment;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();

    // Resolve the NotificationCenter URL in the current panel context. The
    // page's `getUrl()` throws when the page isn't registered, so a failure
    // here cleanly degrades to stock rendering (Clear visible, no View all).
    $notificationCenterUrl = null;

    try {
        $notificationCenterUrl = \Devletes\NotificationsMax\Filament\Pages\NotificationCenter::getUrl();
    } catch (\Throwable) {
        $notificationCenterUrl = null;
    }
@endphp

<div class="fi-no-database">
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        close-button
        :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
        :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
        :icon="$hasNotifications ? null : \Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        :icon-alias="
            $hasNotifications
            ? null
            : \Filament\Notifications\View\NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE
        "
        :icon-color="$hasNotifications ? null : 'gray'"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new \Illuminate\View\ComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div>
                    <h2 class="fi-modal-heading">
                        {{ __('filament-notifications::database.modal.heading') }}

                        @if ($unreadNotificationsCount)
                            <span
                                {{
                                    (new ComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                        'fi-badge fi-size-xs',
                                    ])
                                }}
                            >
                                {{ $unreadNotificationsCount }}
                            </span>
                        @endif
                    </h2>

                    {{-- .capture: wire:navigate's own click handler stops
                         propagation, so a bubble-phase listener never fires. --}}
                    <div
                        class="fi-ac"
                        x-on:click.capture="$event.target.closest('a[href]') && $dispatch('close-modal', { id: 'database-notifications' })"
                    >
                        @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                            {{ $this->markAllNotificationsAsReadAction }}
                        @endif

                        {{-- Clear is hidden when the notification center is enabled; deletion lives there. --}}
                        @if (! $notificationCenterUrl && $this->clearNotificationsAction?->isVisible())
                            {{ $this->clearNotificationsAction }}
                        @endif

                        @if ($notificationCenterUrl)
                            <x-filament::link
                                :href="$notificationCenterUrl"
                                tag="a"
                                size="sm"
                            >
                                {{ __('View all') }}
                            </x-filament::link>
                        @endif
                    </div>
                </div>
            </x-slot>

            @foreach ($notifications as $notification)
                <div
                    x-on:click.capture="$event.target.closest('a[href]') && $dispatch('close-modal', { id: 'database-notifications' })"
                    @class([
                        'fi-no-notification-read-ctn' => ! $notification->unread(),
                        'fi-no-notification-unread-ctn' => $notification->unread(),
                    ])
                >
                    {{ ActionUrlSchemeNormalizer::normalizeNotification($this->getNotification($notification), request())->inline() }}
                </div>
            @endforeach

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>
