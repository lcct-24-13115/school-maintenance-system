@php $runs = $runs ?? collect(); @endphp

<div class="card" style="padding:0;margin-top:16px">
    <div style="padding:16px 18px 6px">
        <h3 class="p6-card-t" style="margin:0">Recent ticket runs</h3>
        <p class="p6-note" style="margin:6px 0 0">The daily job runs at 1:00 AM (Manila time). Every run, the command, and the "Ticket now" button are listed here.</p>
    </div>

    @if ($runs->isEmpty())
        <div class="empty" style="padding:22px 16px"><i class="fa-solid fa-clock-rotate-left"></i>Nothing has run yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Started by</th>
                        <th>Checked</th>
                        <th>Tickets made</th>
                        <th>Skipped</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr>
                            <td data-label="When">{{ $run->started_at?->format('M j, Y g:i A') }}</td>
                            <td data-label="Started by">
                                {{ $run->triggerLabel() }}
                                @if ($run->runner)<div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $run->runner->name }}</div>@endif
                            </td>
                            <td data-label="Checked">{{ $run->schedules_checked }}</td>
                            <td data-label="Tickets made"><b>{{ $run->tickets_created }}</b></td>
                            <td data-label="Skipped">{{ $run->skipped }}</td>
                            <td data-label="Errors">
                                @if ($run->error_count > 0)
                                    <span class="badge nocap ab-fail">{{ $run->error_count }}</span>
                                    @foreach ((array) $run->errors as $error)
                                        <div class="sub" style="font-size:12.5px;color:var(--text-3)">{{ $error }}</div>
                                    @endforeach
                                @else
                                    0
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>