{{-- Shared shell for every system email (Phase 1). Inline styles only: email apps ignore <style>/CSS files.
     Child views use @extends('emails.layout') and fill @section('content'). Optional: @section('preheader'). --}}
@php($b = config('mail.brand'))
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', $b['name'])</title>
</head>
<body style="margin:0;padding:0;background:#F5F9FD;font-family:Arial,Helvetica,sans-serif;color:#2C3C55;">
<span style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F9FD;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #CFE0F1;border-radius:12px;overflow:hidden;">
      <tr><td style="background:{{ $b['color'] }};padding:20px 28px;border-bottom:4px solid {{ $b['gold'] }};">
        <div style="font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:.3px;">{{ $b['name'] }}</div>
        <div style="font-size:12px;color:#CFE0F1;margin-top:2px;">{{ $b['tagline'] }}</div>
      </td></tr>
      <tr><td style="padding:28px;font-size:15px;line-height:1.6;color:#2C3C55;">
        @yield('content')
      </td></tr>
      <tr><td style="padding:16px 28px;background:#F1F6FC;border-top:1px solid #CFE0F1;font-size:12px;line-height:1.5;color:#6b7a90;">
        This is an automatic message from {{ $b['name'] }}. Please do not reply to it.
        @if (! empty($b['support_email']))
          Need help? Write to <a href="mailto:{{ $b['support_email'] }}" style="color:{{ $b['accent'] }};">{{ $b['support_email'] }}</a>.
        @endif
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
