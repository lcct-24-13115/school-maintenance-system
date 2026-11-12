@use('App\Models\TicketReply')

@php
    // Phase 10: the conversation. Reading it needs nothing more than seeing the ticket (this page is already behind
    // TicketPolicy@view); WRITING needs TicketPolicy@reply (the requester, the assignee, the working roles).
    $canReply = auth()->user()->can('reply', $ticket);
    $canInternal = auth()->user()->can('addInternalNote', $ticket);   // Phase 11: staff only
    $finished = $ticket->status->isTerminal();
    $isRequester = (int) $ticket->requester_id === (int) auth()->id();
    $waiting = $ticket->status === \App\Enums\TicketStatus::WaitingForUser;
    $stamp = fn ($d) => $d?->format('M j, Y g:i A') ?? '—';
@endphp

<section class="card" id="replies">
    <div class="card-h"><h3>Conversation <span class="hint" style="font-family:var(--font-body);font-weight:400">({{ $ticket->replies->count() }})</span></h3></div>

    <div class="thread">
        @forelse ($ticket->replies as $reply)
            @php $fromRequester = (int) $reply->user_id === (int) $ticket->requester_id; @endphp
            <div class="msg {{ $fromRequester ? '' : 'staff' }}" id="reply-{{ $reply->id }}"
                 @if ($reply->is_internal) style="border:2px dashed #c9a24a;border-left:2px dashed #c9a24a;background:var(--amber-dim)" @endif>
                <div class="meta">
                    @if ($reply->author)<x-avatar :user="$reply->author" :size="28" />@endif
                    <b>{{ $reply->author?->name ?? 'Former user' }}</b>
                    <span class="badge pr-normal">{{ $fromRequester ? 'Requester' : 'Staff' }}</span>
                    @if ($reply->is_internal)
                        <span class="badge" style="background:#fff;border:1px dashed #c9a24a;color:var(--navy)"><i class="fa-solid fa-eye-slash"></i> Internal note · staff only</span>
                    @endif
                    <span>· {{ $stamp($reply->created_at) }}</span>
                </div>

                @if (filled($reply->body))
                    <div class="body">{{ $reply->body }}</div>
                @endif

                @foreach ($reply->attachments as $file)
                    <x-attachment-row :file="$file" :ticket="$ticket" :detail="false" style="flex-wrap:wrap;margin-top:8px" />
                @endforeach
            </div>
        @empty
            <x-empty-state icon="fa-regular fa-comments" class="compact">No messages yet.</x-empty-state>
        @endforelse
    </div>

    @if ($waiting && $isRequester)
        <p class="hint" style="margin:0 0 14px">
            Maintenance asked you a question. Answer it in the <a href="#actions">Actions</a> box so the ticket can continue; messages here do not restart it.
        </p>
    @endif

    @if ($canReply && $finished)
        <p class="hint" style="margin:0">This ticket is {{ $ticket->status->label() }}. The conversation is closed.</p>
    @elseif ($canReply)
        <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" enctype="multipart/form-data"
              style="padding-top:18px;border-top:1px solid var(--line)">
            @csrf
            <div class="field">
                <label class="lbl" for="reply-body" style="font-size:15px">Write a message</label>
                <textarea id="reply-body" name="body" rows="3" maxlength="{{ TicketReply::BODY_MAX }}" style="min-height:84px">{{ old('body') }}</textarea>
                @if ($errors->thread->has('body'))<p class="field-err">{{ $errors->thread->first('body') }}</p>@endif
            </div>

            @if ($canInternal)
                <label style="display:flex;align-items:flex-start;gap:8px;margin:12px 0 4px;cursor:pointer">
                    <input type="checkbox" name="is_internal" value="1" @checked(old('is_internal')) style="width:auto;margin-top:4px">
                    <span><b>Internal note</b> <span class="hint" style="margin:0">- only staff can see it. The requester cannot see it or be told about it. Internal notes carry no files.</span></span>
                </label>
            @endif

            <x-file-picker name="files" />

            @if ($errors->thread->has('files'))<p class="field-err">{{ $errors->thread->first('files') }}</p>@endif
            @foreach ($errors->thread->get('files.*') as $messages)
                @foreach ((array) $messages as $message)
                    <p class="field-err">{{ $message }}</p>
                @endforeach
            @endforeach

            <button class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-paper-plane"></i> Send message</button>
        </form>
    @endif
</section>
