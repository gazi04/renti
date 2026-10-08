<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Agents\FaqConciergeAgent;
use App\Exceptions\AiRequestFailedException;
use App\Models\Tenant;
use App\Support\FaqGrounding;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Answers one storefront question from the operator's FAQ knowledge base, and is
 * the security boundary for the only AI output in this app that reaches an
 * anonymous third party with no operator review.
 *
 * Two gates, in order, and both must pass before a word of model prose is
 * returned:
 *
 * 1. `confident` — the model's own claim that the FAQ covered the question. On
 *    false the answer is discarded for the localized "contact the operator"
 *    line, so the model never authors its own fallback.
 * 2. `source_quote` — verified against the FAQ by App\Support\FaqGrounding.
 *    Gate 1 alone was the whole check until 2026-09-12, and a field the model
 *    fills in is not a security control: a jailbreak that set it true published
 *    a fabricated policy in the operator's branding.
 *
 * Both failures land on the same contact line rather than an exception. A
 * question the FAQ cannot answer is not an error, and the widget renders
 * AiRequestFailedException as "something went wrong" — the wrong thing to show a
 * visitor who simply asked about something the operator never wrote down.
 */
class FaqConciergeService
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     *
     * @throws AiRequestFailedException
     */
    public function answer(Tenant $tenant, string $question, string $locale = 'en', array $history = []): string
    {
        $knowledge = trim((string) $tenant->localizedSetting('faq_content', ''));

        // Nothing to ground on: answer without spending a call. The widget
        // already refuses to render in this state and re-asserts it in ask(),
        // but that is a UI gate on one caller — with no FAQ the prompt would
        // otherwise carry an empty "=== FAQ TEXT ===" block and invite exactly
        // the invention the quote check exists to catch.
        if ($knowledge === '') {
            return $this->contactFallback($tenant);
        }

        $language = $locale === 'sq' ? 'Albanian' : 'English';

        try {
            /** @var StructuredAgentResponse $response */
            $response = new FaqConciergeAgent($knowledge, $language, $history)->prompt($question);
        } catch (Throwable $throwable) {
            throw AiRequestFailedException::wrap($throwable);
        }

        /** @var array{answer?: string, confident?: bool, source_quote?: string} $result */
        $result = $response->toArray();

        if (! ($result['confident'] ?? false)) {
            return $this->contactFallback($tenant);
        }

        $answer = trim($result['answer'] ?? '');

        if ($answer === '') {
            throw AiRequestFailedException::malformedResponse();
        }

        $quote = trim($result['source_quote'] ?? '');

        if (! FaqGrounding::supports($knowledge, $quote)) {
            // Metadata only — never the question, answer or quote. This is the
            // one signal that someone is probing the widget, or that the model
            // has started paraphrasing instead of copying (which would show up
            // as every answer falling back), and neither is worth turning the
            // log into a store of visitor text.
            Log::warning('Concierge answer failed grounding', [
                'tenant_id' => $tenant->id,
                'answer_length' => mb_strlen($answer),
                'quote_length' => mb_strlen($quote),
            ]);

            return $this->contactFallback($tenant);
        }

        return $answer;
    }

    /**
     * The localized "I'm not sure — contact the operator" line, with the
     * operator's own phone/email appended when they are set.
     */
    private function contactFallback(Tenant $tenant): string
    {
        $contact = array_filter([
            $tenant->setting('contact_phone'),
            $tenant->setting('contact_email'),
        ]);

        return $contact === []
            ? __('booking.concierge_fallback')
            : __('booking.concierge_fallback_with_contact', ['contact' => implode(' / ', $contact)]);
    }
}
