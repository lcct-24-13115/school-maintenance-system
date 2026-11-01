@extends('emails.layout')

@section('title', $headline)
@section('preheader', $intro)

@section('content')
  <h2 style="margin:0 0 12px;font-size:20px;color:#1B3355;">{{ $headline }}</h2>
  <p style="margin:0 0 12px;">Hello {{ $name }},</p>
  <p style="margin:0 0 16px;">{{ $intro }}</p>

  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;margin:0 0 16px;border:1px solid #CFE0F1;border-radius:8px;">
    <tr>
      <td style="padding:8px 12px;color:#6b7a90;width:90px;">Ticket</td>
      <td style="padding:8px 12px;"><strong>{{ $ticket_no }}</strong></td>
    </tr>
    <tr>
      <td style="padding:8px 12px;color:#6b7a90;border-top:1px solid #E3EDF8;">Status</td>
      <td style="padding:8px 12px;border-top:1px solid #E3EDF8;">{{ $status }}</td>
    </tr>
    @foreach ($details as $label => $value)
      <tr>
        <td style="padding:8px 12px;color:#6b7a90;border-top:1px solid #E3EDF8;vertical-align:top;">{{ $label }}</td>
        <td style="padding:8px 12px;border-top:1px solid #E3EDF8;">{{ $value }}</td>
      </tr>
    @endforeach
  </table>

  @if (! empty($note))
    <div style="margin:0 0 16px;padding:10px 14px;border-left:4px solid {{ config('mail.brand.gold') }};background:#F1F6FC;font-size:14px;white-space:pre-line;">{{ $note }}</div>
  @endif

  @if ($confidential)
    <p style="margin:0 0 16px;font-size:13px;color:#6b7a90;">This ticket is confidential, so its details are not shown in this email. Open the ticket to read them.</p>
  @endif

  <p style="margin:0 0 4px;">
    <a href="{{ $url }}" style="display:inline-block;background:{{ config('mail.brand.color') }};color:#ffffff;text-decoration:none;font-weight:bold;padding:11px 22px;border-radius:8px;">Open ticket</a>
  </p>
@endsection
