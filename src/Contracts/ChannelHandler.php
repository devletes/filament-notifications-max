<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Devletes\NotificationsMax\Notifications\GenericNotification;

/**
 * Contract for a single notification channel's rendering logic. One handler
 * per physical channel name (`database`, `broadcast`, `mail`, `twilio`,
 * `slack`, …) — the channel name is the same one Laravel's ChannelManager
 * resolves and the host's `via()` returns.
 *
 * The package ships handlers for every channel it pre-loads. Each
 * `GenericNotification::to{Channel}()` method is a one-line delegate that
 * resolves the handler from `notifications-max.channel_handlers` config
 * and forwards the call. Hosts customise a built-in channel's rendering
 * by pointing the config at their own handler class; they add a wholly
 * new channel by subclassing `GenericNotification` and defining their
 * own `to{Channel}()` method.
 *
 * Why a class (rather than the `to{Channel}()` method living on
 * `GenericNotification` itself): one shared notification class is used
 * across every type-key in the registry. Putting per-channel logic in
 * handlers keeps `GenericNotification` lean and testable, and lets
 * channel rendering be swapped at the config level without subclassing.
 */
interface ChannelHandler
{
    /**
     * Render the channel's payload. Return type varies per channel —
     * Laravel's matching channel class dictates what shape it expects:
     *
     *   - `database` → `array<string, mixed>`
     *   - `broadcast` → `\Illuminate\Notifications\Messages\BroadcastMessage`
     *   - `mail` → `\Illuminate\Notifications\Messages\MailMessage`
     *   - `twilio` → `\NotificationChannels\Twilio\TwilioSmsMessage`
     *   - `slack` → whatever the slack channel package expects
     *   - host-defined channel → whatever its Laravel channel class consumes
     */
    public function send(object $notifiable, GenericNotification $notification): mixed;
}
