{{-- Alpine image carousel. $photos = [['web' => url, 'thumb' => url], …]

     Touch-swipe and arrow keys were added alongside the buttons: on a phone the
     arrows overlay the photo, and swiping is what a visitor actually reaches for. --}}
@if (count($photos) > 0)
    <div x-data="{
            current: 0,
            count: {{ count($photos) }},
            touchX: null,
            next() { this.current = (this.current + 1) % this.count },
            prev() { this.current = (this.current - 1 + this.count) % this.count },
            onTouchEnd(e) {
                if (this.touchX === null) return;
                const dx = e.changedTouches[0].clientX - this.touchX;
                if (Math.abs(dx) > 40) { dx < 0 ? this.next() : this.prev() }
                this.touchX = null;
            },
         }"
         class="select-none"
         wire:ignore.self
         role="group"
         aria-roledescription="carousel"
         aria-label="{{ $vehicle->name }}">
        {{-- Main slide track --}}
        <div class="relative aspect-video overflow-hidden rounded-panel bg-surface-sunken"
             tabindex="0"
             @keydown.arrow-right.prevent="next()"
             @keydown.arrow-left.prevent="prev()"
             @touchstart.passive="touchX = $event.changedTouches[0].clientX"
             @touchend.passive="onTouchEnd($event)">
            <div class="flex h-full transition-transform duration-300 ease-out"
                 :style="`transform: translateX(-${current * 100}%)`">
                @foreach ($photos as $photo)
                    <img src="{{ $photo['web'] }}"
                         alt="{{ $vehicle->name }} — {{ $loop->iteration }}"
                         class="h-full w-full shrink-0 object-cover"
                         @if (! $loop->first) loading="lazy" @endif>
                @endforeach
            </div>

            <x-ui.badge tone="surface" class="absolute left-3 top-3">{{ $vehicle->category->getLabel() }}</x-ui.badge>

            @if (count($photos) > 1)
                <button type="button"
                        @click="prev()"
                        class="absolute start-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-surface-raised/80 text-ink shadow hover:bg-surface-raised focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        aria-label="{{ __('booking.photo_previous') }}">
                    <flux:icon.chevron-left class="size-5" />
                </button>
                <button type="button"
                        @click="next()"
                        class="absolute end-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-surface-raised/80 text-ink shadow hover:bg-surface-raised focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                        aria-label="{{ __('booking.photo_next') }}">
                    <flux:icon.chevron-right class="size-5" />
                </button>

                {{-- Dots: the visual dot stays 8px, the hit area is 44px. --}}
                <div class="absolute bottom-1 left-1/2 flex -translate-x-1/2">
                    @foreach ($photos as $index => $photo)
                        <button type="button"
                                @click="current = {{ $index }}"
                                class="flex size-11 items-center justify-center focus-visible:outline-none"
                                :aria-current="current === {{ $index }} ? 'true' : 'false'"
                                aria-label="{{ __('booking.photo_show', ['number' => $index + 1]) }}">
                            <span class="block size-2 rounded-full transition-colors"
                                  :class="current === {{ $index }} ? 'bg-ink-inverse' : 'bg-ink-inverse/50'"></span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Thumbnail strip --}}
        @if (count($photos) > 1)
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                @foreach ($photos as $index => $photo)
                    <button type="button"
                            @click="current = {{ $index }}"
                            class="shrink-0 overflow-hidden rounded-control border-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                            :class="current === {{ $index }} ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                            aria-label="{{ __('booking.photo_show', ['number' => $index + 1]) }}">
                        <img src="{{ $photo['thumb'] }}" alt="" loading="lazy" class="h-14 w-20 object-cover sm:h-16 sm:w-24">
                    </button>
                @endforeach
            </div>
        @endif
    </div>
@else
    <div class="relative flex aspect-video items-center justify-center rounded-panel bg-surface-sunken text-ink-faint">
        <flux:icon.truck class="size-16" />
        <x-ui.badge tone="surface" class="absolute left-3 top-3">{{ $vehicle->category->getLabel() }}</x-ui.badge>
    </div>
@endif
