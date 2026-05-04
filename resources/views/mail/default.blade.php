@component('mail::message')
{{-- Subject is set via the MailMessage's ->subject(); printed here as the headline so the email reads like a real message rather than a templated form. --}}
# {{ $subject }}

{!! $content !!}

{{-- Footer line is intentionally generic; host themes can register a richer template that overrides this one. --}}
@slot('subcopy')
You're receiving this because of your notification preferences. Update them anytime from your account.
@endslot
@endcomponent
