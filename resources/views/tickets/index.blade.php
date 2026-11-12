@php
    $fmt = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
    // open the filter panel when any filter besides the search box is active
    $otherFilters = collect($filters)->except('q')->filter()->isNotEmpty();
    $isHistory = $mode === 'history';
    $me = auth()->user();

    // clickable column headers (desktop); phones use the "Sort by" box in the filter panel
    $columns = [
        'ticket' => 'Ticket', 'status' => 'Status', 'location' => 'Location', 'category' => 'Category',
        'requester' => 'Requester', 'assigned' => 'Assigned to', 'submitted' => 'Submitted',
        'completed' => $isHistory ? 'Closed / completed' : 'Completed',
    ];
    $sortUrl = fn (string $key) => route($listRoute, array_merge(
        request()->except('page'),
        ['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc']
    ));
    $sortIcon = fn (string $key) => $sort === $key ? ($dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    $priorityNow = $filters['priority'] ?: ($filters['emergency'] ? 'emergency' : '');
@endphp

<x-app-layout>
    <x-slot name="header">Tickets</x-slot>

    {{-- Phase 14: My Tickets (active) | Ticket History (Closed / Cancelled / Disapproved) --}}
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px" role="tablist" aria-label="Ticket lists">
        <a href="{{ route('tickets.index') }}" role="tab" aria-selected="{{ $isHistory ? 'false' : 'true' }}"
           class="btn {{ $isHistory ? '' : 'btn-primary' }}"><i class="fa-solid fa-ticket"></i> My Tickets</a>
        <a href="{{ route('tickets.history') }}" role="tab" aria-selected="{{ $isHistory ? 'true' : 'false' }}"
           class="btn {{ $isHistory ? 'btn-primary' : '' }}"><i class="fa-solid fa-clock-rotate-left"></i> Ticket History</a>
    </div>

    {{-- Search + filters + sort (GET, so every result page can be bookmarked and pagination keeps them) --}}
    <form method="GET" action="{{ route($listRoute) }}" class="card"
          x-data="{ open: {{ $otherFilters || $sort ? 'true' : 'false' }} }">
        <div class="filters" style="margin-bottom:0">
            <input type="search" name="q" value="{{ $filters['q'] }}" aria-label="Search tickets"
                   placeholder="Search ticket no, description, location, reason, requester">
            <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <button type="button" class="btn" @click="open = ! open">
                <i class="fa-solid fa-sliders"></i> <span x-text="open ? 'Hide filters ▲' : 'Show filters ▼'"></span>
            </button>
        </div>

        <div x-show="open" style="display:none">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:16px">
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-from">From (submitted)</label>
                    <input id="f-from" type="date" name="from" value="{{ $filters['from'] }}">
                </div>
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-to">To (submitted)</label>
                    <input id="f-to" type="date" name="to" value="{{ $filters['to'] }}">
                </div>

                @if (count($filters['status']) > 1)
                    {{-- a dashboard card can link with several statuses at once; keep them when re-applying --}}
                    <div class="field" style="margin:0">
                        <span class="lbl">Status</span>
                        <div style="display:flex;align-items:center;min-height:46px;padding:8px 15px;border-radius:14px;background:#f6f9fd;border:1.5px solid var(--line);font-size:14.5px;font-weight:600;color:var(--navy)">
                            {{ collect($filters['status'])->map(fn ($v) => $statusOptions[$v])->implode(', ') }}
                        </div>
                        @foreach ($filters['status'] as $v)<input type="hidden" name="status[]" value="{{ $v }}">@endforeach
                    </div>
                @else
                    <div class="field" style="margin:0">
                        <label class="lbl" for="f-status">Status</label>
                        <select id="f-status" name="status[]">
                            <option value="">All statuses</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(in_array($value, $filters['status'], true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @unless ($isHistory)
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-priority">Priority</label>
                    <select id="f-priority" name="priority">
                        <option value="">All priorities</option>
                        <option value="emergency" @selected($priorityNow === 'emergency')>Emergency</option>
                        <option value="routine" @selected($priorityNow === 'routine')>Routine</option>
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-category">Category</label>
                    <select id="f-category" name="category_id">
                        <option value="">All categories</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected((string) $filters['category_id'] === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-assigned">Assigned to</label>
                    <select id="f-assigned" name="assigned_to">
                        <option value="">Anyone</option>
                        @if ($me->isMaintenanceTeam())
                            <option value="me" @selected($filters['assigned_to'] === 'me' || (string) $filters['assigned_to'] === (string) $me->id)>Me</option>
                        @endif
                        <option value="unassigned" @selected($filters['assigned_to'] === 'unassigned')>Unassigned (none)</option>
                        @foreach ($assignees as $a)
                            @continue($me->isMaintenanceTeam() && $a->id === $me->id)
                            <option value="{{ $a->id }}" @selected((string) $filters['assigned_to'] === (string) $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-department">Department</label>
                    <select id="f-department" name="department_id">
                        <option value="">All departments</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected((string) $filters['department_id'] === (string) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endunless

                <div class="field" style="margin:0">
                    <label class="lbl" for="f-sort">Sort by</label>
                    <select id="f-sort" name="sort">
                        <option value="">Default order</option>
                        @foreach ($sortOptions as $value => $label)
                            <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label class="lbl" for="f-dir">Order</label>
                    <select id="f-dir" name="dir">
                        <option value="asc" @selected($dir === 'asc')>Ascending (A–Z, oldest first)</option>
                        <option value="desc" @selected($dir === 'desc')>Descending (Z–A, newest first)</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:10px 28px;margin-top:16px">
                @unless ($isHistory)
                    <label class="check"><input type="checkbox" name="overdue" value="1" @checked($filters['overdue'])> Overdue only</label>
                @endunless
                <label class="check"><input type="checkbox" name="mine" value="1" @checked($filters['mine'])> Only tickets I filed</label>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px">
            <button class="btn btn-primary">Apply</button>
            @if ($hasFilters || $sort)
                <a href="{{ route($listRoute) }}" class="btn btn-danger">{{ $hasFilters ? 'Clear filters' : 'Clear sort' }}</a>
            @endif
            @if ($canExport)
                {{-- same search + filters as the list; every matching ticket, not just this page --}}
                <a href="{{ route($isHistory ? 'tickets.history.export' : 'tickets.export', request()->except('page')) }}"
                   class="btn" style="margin-left:auto"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
            @endif
        </div>

        @if ($errors->any())
            <p class="field-err">{{ $errors->first() }}</p>
        @endif
    </form>

    @if ($tickets->isEmpty())
        <div class="card">
            <x-empty-state icon="fa-regular fa-folder-open" title="No tickets found.">
                {{ $hasFilters ? 'Try a different search or clear the filters.' : ($isHistory ? 'No ticket has been closed, cancelled or disapproved yet.' : 'There are no active tickets you can see.') }}
                @if ($hasFilters)
                    <br><a href="{{ route($listRoute) }}" class="btn btn-sm">Clear filters</a>
                @endif
            </x-empty-state>
        </div>
    @else
        <p class="hint" style="margin:0 0 10px">
            {{ $tickets->total() }} ticket(s) ·
            @if ($sort)
                sorted by {{ strtolower($sortOptions[$sort]) }} ({{ $dir === 'asc' ? 'ascending' : 'descending' }})
            @else
                {{ $isHistory ? 'most recently finished first' : 'emergencies first, then oldest first' }}
            @endif
        </p>

        {{-- Desktop: table. Phones: the .stack rule in style.css turns every row into a card. --}}
        <div class="table-wrap">
            <table class="stack">
                <thead>
                    <tr>
                        @foreach ($columns as $key => $label)
                            <th aria-sort="{{ $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                <a href="{{ $sortUrl($key) }}" title="Sort by {{ strtolower($label) }}"
                                   style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                                    {{ $label }}
                                    <i class="fa-solid {{ $sortIcon($key) }}" style="{{ $sort === $key ? '' : 'opacity:.35' }}"></i>
                                </a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tickets as $t)
                        <tr @if ($t->is_emergency && ! $t->status->isTerminal()) style="background:var(--red-dim)" @endif>
                            <td data-label="Ticket"><a href="{{ route('tickets.show', $t) }}" style="font-weight:800">{{ $t->ticket_no }}</a></td>
                            <td data-label="Status">
                                <div style="display:flex;flex-wrap:wrap;gap:6px">@include('tickets._badges', ['ticket' => $t])</div>
                            </td>
                            <td data-label="Location">{{ $t->headline() }}</td>
                            <td data-label="Category">{{ $t->category?->name }}@if ($t->subcategory)<div class="sub">{{ $t->subcategory->name }}</div>@endif</td>
                            <td data-label="Requester">{{ $t->requester->name }}<div class="sub">{{ $t->department?->name }}</div></td>
                            <td data-label="Assigned to">{{ $t->assignee?->name ?? '—' }}</td>
                            <td data-label="Submitted" class="mono" style="white-space:nowrap">{{ $fmt($t->submitted_at) }}</td>
                            <td data-label="Completed" class="mono" style="white-space:nowrap">{{ $fmt($isHistory ? ($t->closed_at ?? $t->completed_at) : $t->completed_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pager (keeps the search + filters + sort because the controller uses withQueryString()) --}}
        @if ($tickets->hasPages())
            @php
                $cur = $tickets->currentPage();
                $last = $tickets->lastPage();
                $pages = collect([1, $last])->merge(range(max(1, $cur - 2), min($last, $cur + 2)))->unique()->sort()->values();
                $prev = null;
            @endphp
            <nav class="pager" aria-label="Pagination">
                @if ($tickets->onFirstPage())
                    <span style="opacity:.5">&lsaquo; Prev</span>
                @else
                    <a href="{{ $tickets->previousPageUrl() }}" rel="prev">&lsaquo; Prev</a>
                @endif

                @foreach ($pages as $p)
                    @if ($prev !== null && $p - $prev > 1)<span>…</span>@endif
                    @if ($p === $cur)
                        <span class="cur" aria-current="page">{{ $p }}</span>
                    @else
                        <a href="{{ $tickets->url($p) }}">{{ $p }}</a>
                    @endif
                    @php $prev = $p; @endphp
                @endforeach

                @if ($tickets->hasMorePages())
                    <a href="{{ $tickets->nextPageUrl() }}" rel="next">Next &rsaquo;</a>
                @else
                    <span style="opacity:.5">Next &rsaquo;</span>
                @endif
            </nav>
        @endif
    @endif

    @push('scripts')
        @include('partials.live-refresh')
    @endpush
</x-app-layout>