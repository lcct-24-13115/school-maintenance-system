{{-- Help & FAQ Phase 5B: what to write next. Needs $insights = [helpful, not_helpful, misses]. --}}
<section class="faq-insights" aria-label="Help page insights">
    <div class="card">
        <h3 class="p6-card-t">Most helpful</h3>
        @if ($insights['helpful']->isEmpty())
            <p class="faq-ins-empty">No &ldquo;helpful&rdquo; votes yet.</p>
        @else
            <ol class="faq-ins-list">
                @foreach ($insights['helpful'] as $row)
                    <li>
                        <a href="{{ route('admin.faq.edit', $row->id) }}">{{ $row->question }}</a>
                        <span class="faq-ins-n">{{ $row->helpful_count }} {{ \Illuminate\Support\Str::plural('vote', $row->helpful_count) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <div class="card">
        <h3 class="p6-card-t">Not helpful</h3>
        @if ($insights['not_helpful']->isEmpty())
            <p class="faq-ins-empty">No &ldquo;not helpful&rdquo; votes yet.</p>
        @else
            <ol class="faq-ins-list">
                @foreach ($insights['not_helpful'] as $row)
                    <li>
                        <a href="{{ route('admin.faq.edit', $row->id) }}">{{ $row->question }}</a>
                        <span class="faq-ins-n">{{ $row->not_helpful_count }} {{ \Illuminate\Support\Str::plural('vote', $row->not_helpful_count) }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="faq-ins-hint">These answers may need a rewrite.</p>
        @endif
    </div>

    <div class="card">
        <h3 class="p6-card-t">Searches with no answer</h3>
        @if ($insights['misses']->isEmpty())
            <p class="faq-ins-empty">Every search found something so far.</p>
        @else
            <ol class="faq-ins-list">
                @foreach ($insights['misses'] as $miss)
                    <li>
                        <span class="faq-ins-term">{{ $miss->term }}</span>
                        <span class="faq-ins-n">{{ $miss->count }}&times; &middot; {{ $miss->last_seen_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="faq-ins-hint">People searched for these and found nothing. Write an answer for the ones at the top.</p>
        @endif
    </div>
</section>
