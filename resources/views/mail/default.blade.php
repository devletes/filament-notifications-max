{{-- Forward brand vars into the mail::message scope. Laravel's @component
     directive does NOT auto-inherit parent scope (only data passed as the
     second arg propagates), so themes that read `$logo`, `$logoDark`,
     `$brand`, `$brandUrl` (Orbit, and any compatible Laravel mail theme)
     only see these when handed over explicitly. --}}
@component('mail::message', [
    'logo' => $logo ?? null,
    'logoDark' => $logoDark ?? null,
    'brand' => $brand ?? null,
    'brandUrl' => $brandUrl ?? null,
])
# {{ $subject }}

{!! $content !!}

{{-- Action button. Laravel's MailChannel auto-merges $actionText / $actionUrl
     into the view data from the MailMessage's ->action() call, so the view
     only has to opt in to rendering them. Skipped when no action is set —
     types without an actionable destination (purely informational) shouldn't
     render an empty button. --}}
@isset($actionText)
@component('mail::button', ['url' => $actionUrl])
{{ $actionText }}
@endcomponent
@endisset

{{-- Footer line is intentionally generic; host themes can register a richer template that overrides this one. --}}
@slot('subcopy')
You're receiving this because of your notification preferences. Update them anytime from your account.
@endslot
@endcomponent
