@php
    // Shared by "Add a department" and "Edit department". $department is a new (unsaved) model on create.
    $isEdit = $department->exists;
@endphp

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="row2">
        <div class="field">
            <label for="dep-name">Name <span class="hint" style="display:inline">(required)</span></label>
            <input id="dep-name" type="text" name="name" maxlength="100" required
                   value="{{ old('name', $department->name) }}">
            @error('name')<div class="field-err">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="dep-code">Code <span class="hint" style="display:inline">(optional, e.g. REG)</span></label>
            <input id="dep-code" type="text" name="code" maxlength="20"
                   value="{{ old('code', $department->code) }}">
            @error('code')<div class="field-err">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="field">
        <label for="dep-desc">Description</label>
        <textarea id="dep-desc" name="description" maxlength="500" rows="3">{{ old('description', $department->description) }}</textarea>
        @error('description')<div class="field-err">{{ $message }}</div>@enderror
    </div>

    <div class="field">
        <label>Receives tickets</label>
        <input type="hidden" name="is_service_department" value="0">
        <label class="p6-check" style="display:flex;gap:10px;align-items:center;font-weight:600">
            <input type="checkbox" name="is_service_department" value="1"
                   @checked(old('is_service_department', $department->is_service_department))>
            This office RECEIVES maintenance tickets (it has its own work queue).
        </label>
        <div class="p6-hint">Turn this off for an office that only files requests. No department name or code is hardcoded anywhere.</div>
    </div>

    @if ($isEdit)
        <div class="field">
            <label for="dep-head">Department Head</label>
            <select id="dep-head" name="head_user_id">
                <option value="">No head assigned</option>
                @foreach ($headOptions as $h)
                    <option value="{{ $h->id }}" @selected((string) old('head_user_id', $department->head_user_id) === (string) $h->id)>
                        {{ $h->name }} ({{ $h->email }})
                    </option>
                @endforeach
            </select>
            @error('head_user_id')<div class="field-err">{{ $message }}</div>@enderror
            <div class="p6-hint">
                Only active Heads / administrators of this department (and global administrators) are offered.
                To make someone a Head or Staff member, set their <b>role</b> and <b>department</b> in
                <a href="{{ route('admin.users.index', ['department' => $department->id]) }}">Admin &rsaquo; Users</a>.
            </div>
        </div>
    @endif

    <div class="p6-save">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> {{ $isEdit ? 'Save changes' : 'Add department' }}</button>
        <a href="{{ route('admin.departments.index') }}" class="btn btn-sm">Cancel</a>
    </div>
</form>
