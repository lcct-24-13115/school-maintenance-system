@php
    $area = 'min-height:84px';
    $when = fn ($d) => $d?->timezone(config('app.timezone'))->format('M j, Y g:i A');
@endphp

<x-app-layout>
    <x-slot name="header">{{ $form->reference_no }}</x-slot>

    <div style="max-width:none">
        @if (session('status'))
            <div class="alert ok"><i class="fa-solid fa-circle-check"></i><div>{{ session('status') }}</div></div>
        @endif
        @if (session('error'))
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ session('error') }}</div></div>
        @endif
        @foreach ($errors->all() as $message)
            <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $message }}</div></div>
        @endforeach

        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 18px">
            @include('facilities._status', ['status' => $form->status])
            <span class="push"></span>
            <a href="{{ route('facilities.index') }}" class="btn btn-sm"><i class="fa-solid fa-list"></i> All requests</a>
            <a href="{{ route('facilities.print', $form) }}" target="_blank" rel="noopener" class="btn btn-sm"><i class="fa-solid fa-print"></i> Print copy</a>
        </div>

        <div class="two-col">
            <div>
                @if (($conflicts ?? collect())->isNotEmpty())
                    <div class="alert warn" role="alert">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <div>
                            <strong>Same facility, same date.</strong>
                            {{ $conflicts->count() }} other open {{ $conflicts->count() === 1 ? 'request wants' : 'requests want' }} the same facility on {{ $form->date_needed?->format('M j, Y') }}. Check the times before you sign.
                            @foreach ($conflicts as $c)
                                <div style="margin-top:6px">
                                    <a href="{{ route('facilities.show', $c) }}" style="font-weight:700">{{ $c->reference_no }}</a>
                                    &middot; {{ \Carbon\Carbon::parse($c->time_needed)->format('g:i A') }}
                                    &middot; {{ $c->facilities->whereIn('id', $form->facilities->pluck('id'))->where('name', '!=', 'Others')->pluck('name')->implode(', ') }}
                                    &middot; {{ $c->requester?->name }} ({{ ucfirst($c->status) }})
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                {{-- The request, in the order of the paper form --}}
                <div class="card">
                    <div class="card-h"><h3>Use of Facilities &amp; Equipment</h3></div>
                    <div class="pf-row"><span class="k">Date filed</span><span class="mono">{{ $form->date_filed?->format('M j, Y') }}</span></div>
                    <div class="pf-row"><span class="k">Office/Department</span><span class="mono">{{ $form->department?->name }}</span></div>
                    <div class="pf-row"><span class="k">Person responsible</span><span class="mono">{{ $form->requester?->name }}</span></div>
                    <div class="pf-row"><span class="k">Purpose</span><span class="mono" style="word-break:break-word">{{ $form->purpose }}</span></div>
                    <div class="pf-row"><span class="k">No. of person to use</span><span class="mono">{{ $form->persons_count }}</span></div>
                    <div class="pf-row"><span class="k">Date needed</span><span class="mono">{{ $form->date_needed?->format('M j, Y (l)') }}</span></div>
                    <div class="pf-row"><span class="k">Time</span><span class="mono">{{ \Carbon\Carbon::parse($form->time_needed)->format('g:i A') }}</span></div>

                    <div class="lbl" style="margin-top:14px">Facility to use</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($form->facilities as $fac)
                            <span class="badge" style="color:var(--navy);background:var(--mist);border-color:var(--line);text-transform:none">
                                {{ $fac->name }}@if ($fac->name === 'Others' && $form->other_facility): {{ $form->other_facility }}@endif
                            </span>
                        @endforeach
                    </div>

                    <div class="lbl" style="margin-top:14px">Equipment/Materials needed</div>
                    @forelse ($form->items as $item)
                        <div class="pf-row"><span class="k">{{ $item->quantity }} &times;</span><span class="mono" style="word-break:break-word">{{ $item->description }}</span></div>
                    @empty
                        <p class="hint" style="margin:0">None listed.</p>
                    @endforelse

                    <p class="hint" style="font-style:italic;font-weight:600;margin:16px 0 0">
                        I hereby certify that I/we shall be responsible for the proper use of school facilities and retrieval of equipment/materials.
                    </p>
                </div>

                {{-- Returned by / Date Returned --}}
                <div class="card">
                    <div class="card-h"><h3>Returned</h3></div>
                    @if ($form->returned_at)
                        <div class="pf-row"><span class="k">Returned by</span><span class="mono">{{ $form->returned_by_name }}</span></div>
                        <div class="pf-row"><span class="k">Date returned</span><span class="mono">{{ $form->returned_at->format('M j, Y') }}</span></div>
                        <div class="pf-row"><span class="k">Recorded by</span><span class="mono">{{ $form->receiver?->name ?? '-' }}</span></div>
                    @elseif ($canReturn)
                        <form method="POST" action="{{ route('facilities.returned', $form) }}">
                            @csrf
                            <div class="field">
                                <label class="lbl" for="returned_by_name">Returned by <span style="color:var(--red)">*</span></label>
                                <input id="returned_by_name" type="text" name="returned_by_name" value="{{ old('returned_by_name', $form->requester?->name) }}" maxlength="255" required>
                            </div>
                            <div class="field">
                                <label class="lbl" for="returned_at">Date returned <span style="color:var(--red)">*</span></label>
                                <input id="returned_at" type="date" name="returned_at" value="{{ old('returned_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                            </div>
                            <button class="btn btn-primary"><i class="fa-solid fa-box-archive"></i> Record return</button>
                        </form>
                    @else
                        <p class="hint" style="margin:0">The Property Custodian fills this in after the equipment or facility is returned. It can only be done once the request is approved.</p>
                    @endif
                </div>
            </div>

            <aside style="align-self:start">
                {{-- The signature lines --}}
                <div class="card">
                    <div class="card-h"><h3>Signatures</h3></div>
                    @foreach ($form->approvals as $line)
                        @php
                            $isNext = $nextLine && $nextLine->id === $line->id;
                            $icon = $line->status === 'approved' ? 'fa-circle-check' : ($line->status === 'rejected' ? 'fa-circle-xmark' : 'fa-hourglass-half');
                            $color = $line->status === 'approved' ? 'var(--green)' : ($line->status === 'rejected' ? 'var(--red)' : ($isNext ? 'var(--blue)' : 'var(--text-2)'));
                        @endphp
                        <div style="display:flex;gap:10px;padding:10px 0;border-top:1px solid var(--line)">
                            <i class="fa-solid {{ $icon }}" style="color:{{ $color }};margin-top:3px"></i>
                            <div style="min-width:0">
                                <div style="font-weight:700;color:var(--navy);word-break:break-word">{{ $line->label }}</div>
                                @if ($line->status === 'pending')
                                    <div class="hint" style="margin:2px 0 0">{{ $isNext ? 'Waiting for this signature now' : 'Waiting' }}</div>
                                @else
                                    <div class="hint" style="margin:2px 0 0">
                                        {{ $line->status === 'rejected' ? 'Disapproved' : 'Signed' }} by {{ $line->signer?->name ?? '-' }} &middot; {{ $when($line->signed_at) }}
                                    </div>
                                    @if ($line->remarks)
                                        <div style="font-size:14px;word-break:break-word">&ldquo;{{ $line->remarks }}&rdquo;</div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                    <p class="hint" style="margin:10px 0 0">Note: request must be made 3 days in advance. The request is still subject for approval.</p>
                </div>

                {{-- Only shown to the person whose turn it is --}}
                @if ($canSign && $nextLine)
                    <div class="card">
                        <div class="card-h"><h3>Your turn</h3></div>
                        <p class="hint">{{ $nextLine->label }}</p>

                        <form method="POST" action="{{ route('facilities.sign', $form) }}" style="background:var(--green-dim);border:1.5px solid #bfe6cc;border-radius:18px;padding:14px 16px;margin-bottom:12px">
                            @csrf
                            <div class="field">
                                <label class="lbl" for="remarks">Remarks (optional)</label>
                                <textarea id="remarks" name="remarks" rows="2" style="{{ $area }}">{{ old('remarks') }}</textarea>
                            </div>
                            <button class="btn btn-primary" style="background:var(--green);border-color:var(--green);width:100%"><i class="fa-solid fa-circle-check"></i> Approve &amp; sign</button>
                        </form>

                        <form method="POST" action="{{ route('facilities.reject', $form) }}" style="background:var(--red-dim);border:1.5px solid #f3c9c5;border-radius:18px;padding:14px 16px">
                            @csrf
                            <div class="field">
                                <label class="lbl" for="reason">Reason to disapprove (required)</label>
                                <textarea id="reason" name="reason" rows="2" required style="{{ $area }}">{{ old('reason') }}</textarea>
                            </div>
                            <button class="btn btn-danger" style="width:100%"><i class="fa-solid fa-circle-xmark"></i> Disapprove</button>
                        </form>
                    </div>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>