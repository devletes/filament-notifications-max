<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @unless ($this->isReadOnly())
            <div class="flex justify-end">
                <x-filament::button type="submit">
                    {{ __('Save settings') }}
                </x-filament::button>
            </div>
        @endunless
    </form>
</x-filament-panels::page>
