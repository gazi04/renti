{{-- Three operator-written selling points. Fixed at 3 by the content model
     (see the `services` key in pages/public/home.blade.php). --}}
<section>
    <x-ui.section-heading>{{ __('booking.home_services_heading') }}</x-ui.section-heading>

    <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-3">
        @foreach ($content['services'] as $index => $service)
            <x-ui.card wire:key="service-{{ $index }}">
                <span @class([
                    'mb-4 flex size-11 shrink-0 items-center justify-center rounded-panel',
                    'bg-primary/10 text-primary' => $index % 2 === 0,
                    'bg-notice-surface text-notice' => $index % 2 === 1,
                ])>
                    <flux:icon.check-badge class="size-6" />
                </span>
                <h3 class="mb-1 text-lg font-semibold text-ink">{{ $service['title'] }}</h3>
                <p class="text-sm text-ink-muted">{{ $service['text'] }}</p>
            </x-ui.card>
        @endforeach
    </div>
</section>
