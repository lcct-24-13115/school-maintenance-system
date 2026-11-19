@php
    $sig = $form->approvals->keyBy('step');
    // printed name over the line: only when that line was really signed
    $name = function (string $step) use ($sig) {
        $l = $sig->get($step);
        if (! $l || $l->status === 'pending') { return ''; }
        return ($l->status === 'rejected' ? 'DISAPPROVED - ' : '').($l->signer?->name ?? '');
    };
    $date = function (string $step) use ($sig) {
        $l = $sig->get($step);
        return $l && $l->status !== 'pending' ? $l->signed_at?->timezone(config('app.timezone'))->format('M j, Y') : '';
    };
    $rows = max(8, $form->items->count());
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->reference_no }} - Requisition Slip</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef1f5; color: #111; font: 14px/1.35 Arial, Helvetica, sans-serif; }
        .bar { max-width: 820px; margin: 14px auto 0; padding: 0 14px; display: flex; gap: 10px; }
        .bar a, .bar button { font: inherit; padding: 8px 16px; border-radius: 8px; border: 1px solid #9fb0c6; background: #fff; color: #17325c; cursor: pointer; text-decoration: none; }
        .sheet { max-width: 820px; margin: 14px auto; background: #fff; padding: 26px 30px; border: 1px solid #cdd5df; }
        .head { display: flex; align-items: center; gap: 14px; justify-content: center; text-align: center; }
        .head img { width: 70px; height: 70px; object-fit: contain; }
        .school { font: bold 24px Georgia, 'Times New Roman', serif; }
        .former { font-weight: bold; }
        .addr { font-size: 11px; }
        .title { text-align: center; font-weight: bold; margin: 10px 0 4px; letter-spacing: .02em; }
        .ref { text-align: right; font-size: 12px; }
        .line { display: flex; align-items: flex-end; gap: 6px; margin: 6px 0; }
        .line .fill { flex: 1; border-bottom: 1px solid #111; min-height: 18px; padding: 0 4px; }
        .grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px 8px; margin: 10px 0; font-size: 13px; }
        .others { grid-column: span 2; display: flex; gap: 4px; align-items: flex-end; }
        .others .fill { flex: 1; border-bottom: 1px solid #111; padding: 0 4px; }
        .box { font-family: 'Courier New', monospace; white-space: pre; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #111; padding: 4px 8px; height: 24px; text-align: left; }
        th { text-align: center; }
        td.q { width: 18%; text-align: center; }
        .cert { font-weight: bold; font-style: italic; margin: 6px 0 22px; }
        .sigs { display: grid; grid-template-columns: 1fr 1fr; gap: 26px 40px; }
        .sig .who { min-height: 20px; border-bottom: 1px solid #111; text-align: center; font-weight: bold; }
        .sig .cap { text-align: center; font-size: 12px; margin-top: 2px; }
        .sig .lbl { font-size: 13px; }
        .sig small { display: block; text-align: center; font-size: 10px; color: #444; }
        .foot { display: grid; grid-template-columns: 1fr 260px; gap: 20px; align-items: end; margin-top: 22px; }
        .ret { border: 1px solid #111; padding: 8px 10px; }
        .note { font-weight: bold; font-size: 12px; }
        .tiny { margin-top: 14px; font-size: 10px; color: #555; text-align: center; }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            .sheet { border: 0; margin: 0; padding: 0; max-width: none; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('requisitions.show', $form) }}">Back to request</a>
    </div>

    <div class="sheet">
        <div class="ref">Ref. no. {{ $form->reference_no }}</div>

        <div class="head">
            <img src="{{ asset('images/lcct-logo.png') }}" alt="LCCT seal">
            <div>
                <div class="school">La Consolacion College Tanauan</div>
                <div class="former">(Formerly Our Lady of Fatima Academy)</div>
                <div class="addr">A. Mabini Avenue, Tanauan City Batangas &bull; www.lcctanauan.edu.ph &bull; (043) 778-5236</div>
            </div>
        </div>

        <div class="title">REQUISITION SLIP</div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px">
            <div class="line"><span>Date Filed:</span><span class="fill">{{ $form->date_filed?->format('M j, Y') }}</span></div>
            <div class="line"><span>Date Needed:</span><span class="fill">{{ $form->date_needed?->format('M j, Y') }}</span></div>
        </div>

        <table>
            <thead>
                <tr><th style="width:9%">QTY</th><th>DESCRIPTION</th><th style="width:24%">ACCOUNT TO BE CHARGED</th><th style="width:16%">TOTAL AMOUNT</th></tr>
            </thead>
            <tbody>
                @for ($i = 0; $i < $rows; $i++)
                    <tr>
                        <td class="q">{{ $form->items[$i]->quantity ?? '' }}</td>
                        <td>{{ $form->items[$i]->description ?? '' }}</td>
                        <td>{{ $form->items[$i]->account_charged ?? '' }}</td>
                        <td style="text-align:right">{{ isset($form->items[$i]) ? $money($form->items[$i]->amount) : '' }}</td>
                    </tr>
                @endfor
                <tr>
                    <td colspan="3" style="text-align:right;font-weight:bold">TOTAL</td>
                    <td style="text-align:right;font-weight:bold">{{ $money($form->total_amount) }}</td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:10px">Please check your request:</div>
        <div class="grid" style="grid-template-columns:repeat(5, auto);justify-content:start;gap:4px 22px">
            @foreach ($types as $key => $label)
                @if ($key === 'others')
                    <div class="others" style="grid-column:auto;min-width:200px">
                        <span><span class="box">[{{ in_array($key, $form->request_types, true) ? 'x' : ' ' }}]</span> Others:</span>
                        <span class="fill">{{ in_array($key, $form->request_types, true) ? $form->other_type : '' }}</span>
                    </div>
                @else
                    <div><span class="box">[{{ in_array($key, $form->request_types, true) ? 'x' : ' ' }}]</span> {{ $label }}</div>
                @endif
            @endforeach
        </div>

        <div class="line"><span>Payee's Name (For check only):</span><span class="fill">{{ $form->payee_name }}</span></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px">
            <div class="line"><span>Department/Office:</span><span class="fill">{{ $form->department?->name }}</span></div>
            <div class="line"><span>Requested by:</span><span class="fill">{{ $form->requester?->name }}</span></div>
        </div>

        <div class="sigs" style="margin-top:18px">
            <div class="sig">
                <div class="lbl">Noted by:</div>
                <div class="who">{{ $name('noted') }}</div>
                <div class="cap">OIC/Dept. Head</div>
                <small>{{ $date('noted') }}</small>
            </div>
            <div class="sig">
                <div class="lbl">Recommending Approval by:</div>
                <div class="who">{{ $name('recommended') }}</div>
                <div class="cap">Director, Finance &amp; Accounting</div>
                <small>{{ $date('recommended') }}</small>
            </div>
            <div class="line" style="align-self:end"><span>Remarks:</span><span class="fill">{{ $form->remarks }}</span></div>
            <div class="sig">
                <div class="lbl">Approved by:</div>
                <div class="who">{{ $name('president') }}</div>
                <div class="cap">School President</div>
                <small>{{ $date('president') }}</small>
            </div>
        </div>

        <div class="foot" style="grid-template-columns:1fr">
            <div class="note"><em>Note: All request must be made three (3) working days in advance.</em></div>
        </div>

        <div class="tiny">Printed from the LCCT Ticketing System &middot; {{ now()->timezone(config('app.timezone'))->format('M j, Y g:i A') }}. The names above are the signatures recorded in the system.</div>
    </div>
</body>
</html>