---
paths:
  - 'app/Services/Ai/**'
---

# Ai

## A model-authored `confident` flag is not a security control — gate the concierge on a verified quote
FaqConciergeService is the security boundary for the only AI output in this app that reaches an anonymous visitor with no operator review (the other three AI features are operator-reviewed before use). Two gates, both required: the model's `confident` flag, AND `App\Support\FaqGrounding::supports()` verifying the model's `source_quote` really occurs in the operator's FAQ. `confident` alone was the whole check until 2026-09-12 — a jailbreak that set it true published a fabricated policy inside the operator's branding.

Do NOT replace the quote check with keyword or embedding overlap between answer and FAQ. `Tenant::localizedSetting()` deliberately falls back across languages ("some content beats none") while the reply language comes from the visitor's locale, so an Albanian answer grounded in an English FAQ is a SUPPORTED setup — overlap scores ~0 on exactly the legitimate case, and still passes fabrications that reuse FAQ vocabulary. A quote is copied from the source, so it verifies in any answer language.

Keep the length bounds in FaqGrounding, never as `->min()`/`->max()` on the JSON schema: requests go out with `strict => true` hard-coded (BuildsTextRequests.php) and minLength/maxLength are outside the subset OpenAI strict structured outputs accept — stating them there can get the whole call rejected.

Grounding failure returns the localized contact line, never AiRequestFailedException (the widget renders that as "something went wrong"). Log metadata only — tenant id and lengths, never the question, answer or quote.

Untested assumption: that the model returns verbatim spans. Tests fake the model, so they only prove the parser; if it starts paraphrasing, every answer silently falls back. The "Concierge answer failed grounding" warning is the production signal. See docs/summary.md, "Concierge grounding".
