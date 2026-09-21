<?php

use App\Enums\PlanFeature;
use App\Enums\VehicleStatus;
use App\Models\Review;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Home')] class extends Component {
    /** @return Collection<int, Vehicle> */
    #[Computed]
    public function featuredVehicles(): Collection
    {
        return Vehicle::query()
            ->where('is_public', true)
            ->where('status', VehicleStatus::Available)
            ->with('media')
            ->latest()
            ->limit(6)
            ->get();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function content(): array
    {
        $tenant = tenant();

        return [
            'hero_heading' => $tenant?->localizedSetting('home_hero_heading') ?: __('booking.home_hero_heading'),
            'hero_subheading' => $tenant?->localizedSetting('home_hero_subheading') ?: __('booking.home_hero_subheading'),
            'hero_cta' => $tenant?->localizedSetting('home_hero_cta_label') ?: __('booking.home_hero_cta'),
            'about_title' => $tenant?->localizedSetting('home_about_title') ?: __('booking.home_about_title'),
            'about_text' => $tenant?->localizedSetting('home_about_text') ?: __('booking.home_about_text'),
            'services' => collect([1, 2, 3])->map(fn (int $i): array => [
                'title' => $tenant?->localizedSetting(sprintf('home_service_%d_title', $i)) ?: __(sprintf('booking.home_service_%d_title', $i)),
                'text' => $tenant?->localizedSetting(sprintf('home_service_%d_text', $i)) ?: __(sprintf('booking.home_service_%d_text', $i)),
            ])->all(),
        ];
    }

    /**
     * Whether to show the home-page review showcase — gated (Standard/Pro).
     */
    #[Computed]
    public function showsReviewShowcase(): bool
    {
        return (tenant()?->allowsFeature(PlanFeature::Reviews) ?? (bool) PlanFeature::Reviews->default())
            && $this->showcaseReviews->isNotEmpty();
    }

    /**
     * A few featured approved reviews across the whole fleet. Auto tenant-scoped.
     *
     * @return Collection<int, Review>
     */
    #[Computed]
    public function showcaseReviews(): Collection
    {
        return Review::query()
            ->where('is_approved', true)
            ->with('vehicle')
            ->latest('submitted_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function averageRating(): ?float
    {
        $average = Review::query()->where('is_approved', true)->avg('rating');

        return $average !== null ? round((float) $average, 1) : null;
    }

    #[Computed]
    public function reviewsCount(): int
    {
        return Review::query()->where('is_approved', true)->count();
    }

    /**
     * Public fleet size for the About-page stat grid. Same visibility filter as
     * featuredVehicles() — never counts private or maintenance-parked vehicles.
     */
    #[Computed]
    public function fleetSize(): int
    {
        return Vehicle::query()
            ->where('is_public', true)
            ->where('status', VehicleStatus::Available)
            ->count();
    }

}; ?>

<div>
    @php
        $content = $this->content;
        $vehicles = $this->featuredVehicles;
    @endphp

    {{-- Full-bleed hero: a plain section with a background. No w-screen escape
         is needed because <main> imposes no container. --}}
    <section class="bg-gradient-to-b from-surface-sunken to-surface">
        <x-ui.container class="py-16 sm:py-20 lg:py-24">
            @include('pages.public.partials.home._hero')
        </x-ui.container>
    </section>

    <x-ui.container class="py-12 sm:py-16">
        @include('pages.public.partials.home._trust-strip')
    </x-ui.container>

    <x-ui.container class="space-y-16 pb-16 sm:space-y-24 sm:pb-24">
        @include('pages.public.partials.home._about')
        @include('pages.public.partials.home._services')
        @include('pages.public.partials.home._featured')

        @if ($this->showsReviewShowcase)
            @include('pages.public.partials.home._reviews')
        @endif
    </x-ui.container>

    <section class="bg-gradient-to-br from-primary to-secondary">
        <x-ui.container class="py-16 sm:py-20">
            @include('pages.public.partials.home._cta-banner')
        </x-ui.container>
    </section>
</div>
