@extends('emails.layout')

@section('title', 'Test email')
@section('preheader', 'Your email settings work.')

@section('content')
  <h2 style="margin:0 0 12px;font-size:20px;color:#1B3355;">Email is working</h2>
  <p style="margin:0 0 12px;">If you can read this, {{ config('mail.brand.name') }} can send emails.</p>
  <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;color:#6b7a90;">
    <tr><td style="padding:2px 12px 2px 0;">Sent</td><td>{{ $sentAt }}</td></tr>
    <tr><td style="padding:2px 12px 2px 0;">Mailer</td><td>{{ config('mail.default') }}</td></tr>
  </table>
@endsection
