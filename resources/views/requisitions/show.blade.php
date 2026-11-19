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
            <a href="{{ route('requisitions.index') }}" class="btn btn-sm"><i class="fa-solid fa-list"></i> All requisitions</a>
            <a href="{{ route('requisitions.print', $form) }}" target="_blank" rel="noopener" class="btn btn-sm"><i class="fa-solid fa-print"></i> Print copy</a>
        </div>

        <div class="two-col">
            <div>
                <div class="card">
                    <div class="card-h"><h3>Requisition Slip</h3></div>
                    <div class="pf-row"><span class="k">Date filed</span><span class="mono">{{ $form->date_filed?->format('M j, Y') }}</span></div>
                    <div class="pf-row"><span class="k">Date needed</span><span class="mono">{{ $form->date_needed?->format('M j, Y (l)') }}</span></div>
                    <div class="pf-row"><span class="k">Department/Office</span><span class="mono">{{ $form->department?->name }}</span></div>
                    <div class="pf-row"><span class="k">Requested by</span><span class="mono">{{ $form->requester?->name }}</span></div>
                    @if ($form->payee_name)
                        <div class="pf-row"><span class="k">Payee's name (for check only)</span><span class="mono">{{ $form->payee_name }}</span></div>
                    @endif

                    <div class="lbl" style="margin-top:14px">Request</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($form->request_types as $t)
                            <span class="badge" style="color:var(--navy);background:var(--mist);border-color:var(--line);text-transform:none">
                                {{ \App\Models\RequisitionSlip::TYPES[$t] ?? $t }}@if ($t === 'others' && $form->other_type): {{ $form->other_type }}@endif
                            </span>
                        @endforeach
                    </div>

                    <div class="lbl" style="margin-top:14px">Items</div>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:14px">
                            <thead>
                                <tr style="text-align:left;color:var(--navy)">
                                    <th style="padding:6px 4px">Qty</th><th style="padding:6px 4px">Description</th>
                                    <th style="padding:6px 4px">Account to be charged</th><th style="padding:6px 4px;text-align:right">Total amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($form->items as $item)
                                    <tr style="border-top:1px solid var(--line)">
                                        <td style="padding:8px 4px">{{ $item->quantity }}</td>
                                        <td style="padding:8px 4px;word-break:break-word">{{ $item->description }}</td>
                                        <td style="padding:8px 4px;word-break:break-word">{{ $item->account_charged ?: '-' }}</td>
                                        <td style="padding:8px 4px;text-align:right">&#8369;{{ number_format($item->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="border-top:2px solid var(--line);font-weight:800">
                                    <td colspan="3" style="padding:8px 4px;text-align:right">Total</td>
                                    <td style="padding:8px 4px;text-align:right">&#8369;{{ number_format($form->total_amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    @if ($form->remarks)
                        <div class="lbl" style="margin-top:14px">Remarks</div>
                        <p style="margin:0;word-break:break-word">{{ $form->remarks }}</p>
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
                    <p class="hint" style="margin:10px 0 0">Note: All request must be made three (3) working days in advance.</p>
                </div>

                {{-- Only shown to the person whose turn it is --}}
                @if ($canSign && $nextLine)
                    <div class="card">
                        <div class="card-h"><h3>Your turn</h3></div>
                        <p class="hint">{{ $nextLine->label }}</p>

                        <form method="POST" action="{{ route('requisitions.sign', $form) }}" style="background:var(--green-dim);border:1.5px solid #bfe6cc;border-radius:18px;padding:14px 16px;margin-bottom:12px">
                            @csrf
                            <div class="field">
                                <label class="lbl" for="remarks">Remarks (optional)</label>
                                <textarea id="remarks" name="remarks" rows="2" style="{{ $area }}">{{ old('remarks') }}</textarea>
                            </div>
                            <button class="btn btn-primary" style="background:var(--green);border-color:var(--green);width:100%"><i class="fa-solid fa-circle-check"></i> Approve &amp; sign</button>
                        </form>

                        <form method="POST" action="{{ route('requisitions.reject', $form) }}" style="background:var(--red-dim);border:1.5px solid #f3c9c5;border-radius:18px;padding:14px 16px">
                            @csrf
                            <div class="field">
                                <label class="lbl" for="reason">Reason to disapprove (required)</label>
                                <textarea id="reason" name="reason" rows="2" required style="{{ $area }}">{{ old('reason') }}</textarea>
                            </div>
                            <button class="btn btn-danger" style="width:100%"><i class="fa-solid fa-circle-xmark"></i> Disapprove</button>
                        </form>
                    </div>
                @endif
                @if ($canClose)
                    <div class="card">
                        <div class="card-h"><h3>Close</h3></div>
                        <p class="hint">Approved by the School President. Close this slip once the request was released/completed.</p>
                        <form method="POST" action="{{ route('requisitions.close', $form) }}" onsubmit="return confirm('Close this requisition slip?')">
                            @csrf
                            <button class="btn btn-primary" style="width:100%"><i class="fa-solid fa-box-archive"></i> Close slip</button>
                        </form>
                    </div>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>