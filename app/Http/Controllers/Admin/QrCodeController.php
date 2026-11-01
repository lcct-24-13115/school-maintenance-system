<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QrCode;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * QR codes admin (Phase 17): make, edit, print and delete. Capability: manage_qr_codes (others get 403).
 *
 * The server only keeps WHAT a code points to, its title and its colour. The picture is drawn in the browser
 * by the npm "qrcode" package (resources/js/qr.js), so there is no image to store.
 */
class QrCodeController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeManage($request);

        return view('admin.qr-codes.index', [
            'codes' => QrCode::query()->latest('id')->get(),
            'kinds' => QrCode::kindLabels(),
            'registrationOpen' => QrCode::registrationOpen(),
            'baseUrl' => QrCode::baseUrl(),
            'baseIsLocal' => QrCode::baseIsLocal(),
            'defaultColor' => QrCode::DEFAULT_COLOR,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);

        if ($errors = $this->problems($data, null)) {
            return back()->withInput()->withErrors($errors);
        }

        $qr = QrCode::create($data + ['created_by' => $request->user()->id]);

        $this->audit($request, "QR code made: {$qr->title} ({$qr->kind_label})", $qr, ['kind' => $qr->kind, 'url' => $qr->url]);

        return back()->with('status', "QR code \"{$qr->title}\" is ready.");
    }

    public function update(Request $request, QrCode $qrCode): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $this->validated($request);

        if ($errors = $this->problems($data, $qrCode)) {
            return back()->withInput()->withErrors($errors);
        }

        $qrCode->fill($data);
        $changed = array_keys($qrCode->getDirty());
        $qrCode->save();

        if ($changed !== []) {
            $this->audit($request, "QR code updated: {$qrCode->title}. Changed: ".implode(', ', $changed), $qrCode, ['kind' => $qrCode->kind, 'url' => $qrCode->url]);
        }

        return back()->with('status', "QR code \"{$qrCode->title}\" saved.");
    }

    public function destroy(Request $request, QrCode $qrCode): RedirectResponse
    {
        $this->authorizeManage($request);

        $title = $qrCode->title;
        $kind = $qrCode->kind;
        $qrCode->delete();

        $this->audit($request, "QR code deleted: {$title} ({$kind})", null, ['kind' => $kind]);

        return back()->with('status', "QR code \"{$title}\" deleted.");
    }

    /** A page with only the QR, big, for printing (GET /admin/qr-codes/{qrCode}/print). */
    public function print(Request $request, QrCode $qrCode): View
    {
        $this->authorizeManage($request);

        return view('admin.qr-codes.print', ['qr' => $qrCode]);
    }

    /** @return array{title: string, kind: string, custom_url: ?string, color: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(array_keys(QrCode::kindLabels()))],
            'custom_url' => [Rule::requiredIf($request->input('kind') === QrCode::KIND_CUSTOM), 'nullable', 'url:http,https', 'max:2000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'custom_url.required' => 'Type the link this QR code should open.',
            'custom_url.url' => 'The link must start with http:// or https://.',
            'color.regex' => 'Pick a colour from the colour box.',
        ]);

        return [
            'title' => trim($data['title']),
            'kind' => $data['kind'],
            // only a custom QR keeps a link; the built-in pages always follow APP_URL
            'custom_url' => $data['kind'] === QrCode::KIND_CUSTOM ? trim((string) $data['custom_url']) : null,
            'color' => strtolower($data['color']),
        ];
    }

    /**
     * Rules that need more than the validator: scannable colour, and Register only while it is open.
     *
     * @param  array{title: string, kind: string, custom_url: ?string, color: string}  $data
     * @return array<string, string> field => message (empty = fine)
     */
    private function problems(array $data, ?QrCode $existing): array
    {
        $errors = [];

        if (QrCode::contrast($data['color']) < QrCode::MIN_CONTRAST) {
            $errors['color'] = 'That colour is too light for a phone to scan. Pick a darker one.';
        }

        // An old Register QR can still be edited after registration closes; a new one cannot be made.
        if ($data['kind'] === QrCode::KIND_REGISTER && ! QrCode::registrationOpen() && $existing?->kind !== QrCode::KIND_REGISTER) {
            $errors['kind'] = 'Public registration is closed, so the Register page does not open. Turn it on first (ALLOW_PUBLIC_REGISTRATION).';
        }

        return $errors;
    }

    private function audit(Request $request, string $description, ?QrCode $subject = null, ?array $meta = null): void
    {
        AuditLogger::log(AuditLogger::QR_CODE_CHANGED, $description, $subject, $request->user(), $meta);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can_do('manage_qr_codes'), 403, 'You do not have access to this page.');
    }
}
