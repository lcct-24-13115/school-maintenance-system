{{-- "Was this helpful?" (Help & FAQ Phase 5A). Needs: $faq, $isGuest, $vote (true / false / null). Works without JavaScript: a plain POST form. --}}
@php
    $thisOne = (int) session('help_feedback_id') === (int) $faq->id;
    $error = $thisOne ? session('help_feedback_error') : null;
    $statusText = $error ?: ($vote === true ? 'You marked this as helpful.' : ($vote === false ? 'You marked this as not helpful.' : ''));
@endphp
<div class="help-feedback">
    <form class="help-feedback-form" method="POST" action="{{ $isGuest ? route('help.public.feedback', $faq) : route('help.feedback', $faq) }}" data-help-feedback-form>
        @csrf
        <span class="help-feedback-q" id="fb-q-{{ $faq->id }}">Was this helpful?</span>
        <button type="submit" name="helpful" value="1" class="btn btn-sm help-fb" aria-pressed="{{ $vote === true ? 'true' : 'false' }}" aria-describedby="fb-q-{{ $faq->id }}">
            <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i> Yes
        </button>
        <button type="submit" name="helpful" value="0" class="btn btn-sm help-fb" aria-pressed="{{ $vote === false ? 'true' : 'false' }}" aria-describedby="fb-q-{{ $faq->id }}">
            <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i> No
        </button>
        <span class="help-feedback-status {{ $error ? 'error' : '' }}" role="status" aria-live="polite">{{ $statusText }}</span>
    </form>
</div>
