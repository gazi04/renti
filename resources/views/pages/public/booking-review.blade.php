<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Review;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Leave a Review')] class extends Component {
    /**
     * Locked, and the sharpest of the four: the `signed` middleware on this route
     * validates the initial GET only, never the /livewire/update that submit()
     * arrives on. submit() writes reviewer_name straight off this model, so a
     * re-pointed booking would publish a review under another customer's name.
     * Full rationale on vehicle-show.blade.php's $vehicle.
     */
    #[Locked]
    public Booking $booking;

    /** True once the booking is not eligible (wrong status) or already reviewed. */
    public bool $unavailable = false;

    public bool $alreadyReviewed = false;

    public bool $submitted = false;

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 0;

    #[Validate('nullable|string|max:1000')]
    public string $comment = '';

    public function mount(Booking $booking): void
    {
        $this->booking = $booking;

        if ($booking->status !== BookingStatus::Completed) {
            $this->unavailable = true;

            return;
        }

        if ($booking->review()->exists()) {
            $this->alreadyReviewed = true;
        }
    }

    public function submit(): void
    {
        // Re-guard on submit: state could have changed since mount, and a second
        // tab must not create a duplicate (enforced by the unique booking_id too).
        abort_if($this->unavailable, 404);

        if ($this->booking->status !== BookingStatus::Completed || $this->booking->review()->exists()) {
            $this->alreadyReviewed = true;

            return;
        }

        $this->validate();

        Review::query()->create([
            'booking_id' => $this->booking->id,
            'vehicle_id' => $this->booking->vehicle_id,
            'customer_id' => $this->booking->customer_id,
            'reviewer_name' => $this->booking->customer_name,
            'rating' => $this->rating,
            'comment' => $this->comment !== '' ? $this->comment : null,
            'is_approved' => false,
            'submitted_at' => now(),
        ]);

        $this->submitted = true;
    }
}; ?>

<div class="mx-auto w-full max-w-lg px-4 py-8 sm:px-6 sm:py-12">
    <x-ui.card pad="lg">
        @if ($submitted)
            <div class="text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-positive-surface text-positive">
                    <flux:icon.check class="size-7" />
                </div>
                <h1 class="mb-2 text-2xl font-bold text-ink">{{ __('booking.review_thanks') }}</h1>
                <x-ui.button :href="route('public.home')" class="mt-4">
                    {{ __('booking.back_to_fleet') }}
                </x-ui.button>
            </div>
        @elseif ($unavailable)
            <div class="text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-surface-sunken text-ink-faint">
                    <flux:icon.information-circle class="size-7" />
                </div>
                <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.review_unavailable') }}</h1>
            </div>
        @elseif ($alreadyReviewed)
            <div class="text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-surface-sunken text-ink-faint">
                    <flux:icon.information-circle class="size-7" />
                </div>
                <h1 class="mb-2 text-xl font-bold text-ink">{{ __('booking.review_already') }}</h1>
            </div>
        @else
            <div class="text-center">
                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <flux:icon.truck class="size-7" />
                </div>
                <h1 class="mb-1 text-2xl font-bold text-ink">{{ __('booking.review_title') }}</h1>
                <p class="mb-6 text-sm text-ink-muted">
                    {{ $booking->vehicle->name }} · {{ $booking->start_date->format('d M Y') }} – {{ $booking->end_date->format('d M Y') }}
                </p>
            </div>

            <form wire:submit="submit" class="space-y-6">
                <div>
                    <label id="rating-label" class="mb-2 block text-[11px] font-bold uppercase tracking-wide text-ink-faint">{{ __('booking.review_rating') }}</label>
                    {{-- role=radio + aria-checked to match the radiogroup: without
                         them a screen reader hears five unlabelled buttons and no
                         indication of which rating is currently chosen. --}}
                    <div class="flex gap-1" role="radiogroup" aria-labelledby="rating-label">
                        @for ($star = 1; $star <= 5; $star++)
                            <button type="button"
                                    wire:click="$set('rating', {{ $star }})"
                                    role="radio"
                                    aria-checked="{{ $star === $rating ? 'true' : 'false' }}"
                                    aria-label="{{ trans_choice('booking.review_star_label', $star, ['count' => $star]) }}"
                                    class="flex size-11 items-center justify-center rounded-control focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <flux:icon.star variant="solid"
                                                class="size-9 {{ $star <= $rating ? 'text-star' : 'text-line-strong' }}" />
                            </button>
                        @endfor
                    </div>
                    @error('rating')
                        <p class="mt-1 text-sm text-critical">{{ $message }}</p>
                    @enderror
                </div>

                <x-ui.field :label="__('booking.review_comment')" for="comment" name="comment">
                    <x-ui.textarea id="comment" wire:model="comment" rows="4" placeholder="{{ __('booking.review_comment_placeholder') }}" />
                </x-ui.field>

                <x-ui.button type="submit" class="w-full">
                    {{ __('booking.review_submit') }}
                </x-ui.button>

                <p class="text-center text-xs text-ink-faint">{{ __('booking.review_link_note') }}</p>
            </form>
        @endif
    </x-ui.card>
</div>
