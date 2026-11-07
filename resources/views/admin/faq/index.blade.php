@php
    $roleName = fn (string $slug) => $roleLabels[$slug] ?? \Illuminate\Support\Str::headline($slug);
    $filtered = $filters['topic'] !== '' || $filters['status'] !== '' || $filters['public'] !== '' || $filters['q'] !== '';
@endphp

<x-app-layout>
    <link rel="stylesheet" href="{{ asset('css/phase6.css') }}?v={{ @filemtime(public_path('css/phase6.css')) }}">

    <x-slot name="header">
        <h2 class="page-title">FAQ manager</h2>
    </x-slot>

    <div class="content p6-wrap">

        @if (session('status'))
            <div class="alert ok" role="status"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ session('error') }}</span></div>
        @endif

        <p class="p6-note">
            These are the questions and answers of the <b>Help &amp; FAQ</b> page.
            <b>Hide</b> an entry to take it off the Help page without deleting it.
            The <b>arrows</b> change the order inside a topic.
            <b>Public</b> entries can also be read before signing in (only entries for Everyone can be public).
        </p>

        <div class="p6-bar" style="margin:0 0 14px">
            <a href="{{ route('admin.faq.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add FAQ</a>
            <a href="{{ route('help.index') }}" class="btn push"><i class="fa-solid fa-circle-question"></i> View Help page</a>
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('admin.faq.index') }}" class="card">
            <div class="p6-grid p6-filters">
                <div class="field">
                    <label for="f-topic">Topic</label>
                    <select id="f-topic" name="topic">
                        <option value="">All topics</option>
                        @foreach ($topics as $t)
                            <option value="{{ $t }}" @selected($filters['topic'] === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="f-status">Status</label>
                    <select id="f-status" name="status">
                        <option value="">Published and hidden</option>
                        <option value="published" @selected($filters['status'] === 'published')>Published only</option>
                        <option value="hidden" @selected($filters['status'] === 'hidden')>Hidden only</option>
                    </select>
                </div>

                <div class="field">
                    <label for="f-public">Before sign-in</label>
                    <select id="f-public" name="public">
                        <option value="">Any</option>
                        <option value="yes" @selected($filters['public'] === 'yes')>Public (shown on the login page)</option>
                    </select>
                </div>

                <div class="field wide">
                    <label for="f-q">Search</label>
                    <input id="f-q" type="search" name="q" maxlength="100" value="{{ $filters['q'] }}" placeholder="Words in the question or the answer">
                    @error('q')<div class="field-err">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="p6-bar">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
                <a href="{{ route('admin.faq.index') }}" class="btn">Clear</a>
                <span class="hint push" style="font-size:13px;color:var(--text-2)">
                    <b>{{ $total }}</b> {{ \Illuminate\Support\Str::plural('entry', $total) }}@if ($filtered) match these filters @endif
                </span>
            </div>
        </form>

        {{-- List --}}
        <div class="card" style="padding:18px">
            <div class="table-wrap">
                <table class="stack">
                    <thead>
                        <tr>
                            <th style="width:96px">Order</th>
                            <th>Question</th>
                            <th>Topic</th>
                            <th>Shown to</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($faqs as $faq)
                            @php
                                $isFirst = ($edges[$faq->topic]['first'] ?? null) == $faq->id;
                                $isLast = ($edges[$faq->topic]['last'] ?? null) == $faq->id;
                                $confirm = "Delete \"{$faq->question}\"? This cannot be undone.";
                            @endphp
                            <tr @if (! $faq->is_published) style="opacity:.62" @endif>
                                <td data-label="Order">
                                    <form method="POST" action="{{ route('admin.faq.move', $faq) }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="direction" value="up">
                                        <button class="btn btn-sm" style="padding:0 11px" title="Move up" aria-label="Move up" @disabled($isFirst)><i class="fa-solid fa-arrow-up"></i></button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.faq.move', $faq) }}" style="display:inline">
                                        @csrf
                                        <input type="hidden" name="direction" value="down">
                                        <button class="btn btn-sm" style="padding:0 11px" title="Move down" aria-label="Move down" @disabled($isLast)><i class="fa-solid fa-arrow-down"></i></button>
                                    </form>
                                </td>

                                <td data-label="Question" style="word-break:break-word">
                                    <b>{{ $faq->question }}</b>
                                    <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $faq->answer), 110) }}</div>
                                </td>

                                <td data-label="Topic" style="word-break:break-word">{{ $faq->topic }}</td>

                                <td data-label="Shown to">
                                    <div class="p6-why">
                                        @if ($faq->is_published)
                                            <span class="badge nocap ab-ok"><i class="fa-solid fa-circle-check"></i> Published</span>
                                        @else
                                            <span class="badge pr-normal nocap"><i class="fa-solid fa-eye-slash"></i> Hidden</span>
                                        @endif

                                        @if ($faq->is_public)
                                            <span class="badge nocap ab-ticket"><i class="fa-solid fa-globe"></i> Public</span>
                                        @endif

                                        @if ($faq->isForEveryone())
                                            <span class="badge pr-normal nocap"><i class="fa-solid fa-users"></i> Everyone</span>
                                        @else
                                            @foreach ($faq->audienceList() as $slug)
                                                <span class="badge pr-normal nocap"><i class="fa-solid fa-user"></i> {{ $roleName($slug) }}</span>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>

                                <td data-label="Actions" style="text-align:right;white-space:nowrap">
                                    <form method="POST" action="{{ route('admin.faq.toggle', $faq) }}" style="display:inline">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm" title="{{ $faq->is_published ? 'Hide from the Help page' : 'Publish on the Help page' }}">
                                            <i class="fa-solid {{ $faq->is_published ? 'fa-eye-slash' : 'fa-eye' }}"></i> {{ $faq->is_published ? 'Hide' : 'Publish' }}
                                        </button>
                                    </form>

                                    <a href="{{ route('admin.faq.edit', $faq) }}" class="btn btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>

                                    <form method="POST" action="{{ route('admin.faq.duplicate', $faq) }}" style="display:inline">
                                        @csrf
                                        <button class="btn btn-sm" title="Make a hidden copy"><i class="fa-solid fa-copy"></i> Duplicate</button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.faq.destroy', $faq) }}" style="display:inline"
                                          onsubmit="return confirm({{ \Illuminate\Support\Js::from($confirm) }})">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-danger" title="Delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="empty">
                                        <i class="fa-regular fa-circle-question"></i>
                                        @if ($filtered)
                                            No entries match these filters.
                                        @else
                                            No FAQ entries yet. Use <b>Add FAQ</b> to write the first one.
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $faqs->links() }}
    </div>
</x-app-layout>