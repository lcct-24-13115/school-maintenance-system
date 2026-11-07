<?php

/*
| FAQ starter content, part 1 (Phase 4C): Getting started, Sending a repair request, Following your request.
| Row = [topic, audience, public?, question, answer, sort_order]. See database/data/faq_starter.php.
| Every answer below describes what the system really does today (docs/PHASE_4A_FACTSHEET.md).
| Nothing here names who authorizes a ticket: that is still an open decision (factsheet D1).
*/

return [

    // ------------------------------------------------------------------ Getting started (public)

    ['Getting started', ['all'], true, 'What is the Maintenance Request System?', <<<'TXT'
It is the online service desk of the school's maintenance office. You use it to report something that needs repair, and to follow what happens to your request until the work is done.

Every request becomes a **ticket** with its own number, for example MT-2026-00001. You can open the ticket at any time to see its status and its history.
TXT, 10],

    ['Getting started', ['all'], true, 'How do I sign in?', <<<'TXT'
1. Open the sign-in page.
2. Type your school e-mail address and your password.
3. Press the sign-in button.

If you type a wrong password 5 times, sign-in is locked for about 15 minutes. Wait, then try again. If you keep having trouble, ask the maintenance office.
TXT, 20],

    ['Getting started', ['all'], true, 'I forgot my password. What do I do?', <<<'TXT'
1. On the sign-in page, choose **Forgot password?**
2. Type your e-mail address.
3. Open the e-mail we send you and use the link inside to choose a new password.

If the e-mail does not arrive, check your spam folder. If it is not there either, ask the maintenance office to help you.
TXT, 30],

    ['Getting started', ['all'], true, 'The system asks me to choose a new password. Why?', <<<'TXT'
When the maintenance office makes your account, it gives you a **temporary password**. The first time you sign in with it, you must choose your own password before you can use anything else.

1. Type the temporary password.
2. Type the new password you want, twice. It must be at least 8 characters, and it must be different from the temporary one.
3. Save. You can now use the system.
TXT, 40],

    ['Getting started', ['all'], true, 'Can I make my own account?', <<<'TXT'
Normally, no. Accounts are made by the maintenance office, which also gives you your first temporary password. If you cannot sign in yet, ask the office for an account.
TXT, 50],

    ['Getting started', ['all'], true, 'What is in the menu?', <<<'TXT'
After you sign in, the menu has these places for everyone:

- **Dashboard**: a quick look at your tickets.
- **My Tickets**: your requests that are still being handled.
- **Ticket History**: your requests that are finished (Closed, Cancelled or Disapproved).
- **New request**: the form to report a problem.
- **Notifications**: the bell, with news about your tickets.
- **Help & FAQ**: this page.

Some people also see more places, depending on their role. If a page is not in your menu, your role does not use it.
TXT, 60],

    // ------------------------------------------------------------------ Sending a repair request

    ['Sending a repair request', ['all'], false, 'How do I send a repair request?', <<<'TXT'
1. Choose **New request** in the menu.
2. Fill in the form (see the next question for each field).
3. Press **SEND REQUEST**.

Your request gets a ticket number, like MT-2026-00001, and the maintenance office is alerted. Keep the number if you want to ask about it.
TXT, 10],

    ['Sending a repair request', ['all'], false, 'What do I fill in on the request form?', <<<'TXT'
These are required:

- **From: (Department/Office)**: who is asking.
- **Maintenance category**: the kind of work, for example plumbing or electrical.
- **Where is it? (building / room)**: so the staff can find it.
- **Job Description**: what is wrong or what you need (up to 1000 characters).
- **Reason for Request**: why it is needed (up to 2000 characters).
- **Priority**: First Come First Served, or Emergency (see "How do I ask for an emergency?").
- **Date needed**: when you need it done.

Optional: **Request type**, **Photos & documents**, and **Mark as confidential**.

If you forget something, the form shows a message under that field and keeps what you already typed.
TXT, 20],

    ['Sending a repair request', ['all'], false, 'What does "Date needed" mean? Why is it refused?', <<<'TXT'
It is the date you hope the work is finished. It must be at least **5 working days** from today. Saturdays and Sundays are not counted. School holidays are also counted as normal days.

If the date is too early, the form says so. Choose a later date. If the work is urgent, use the emergency choice instead (see the next question).
TXT, 30],

    ['Sending a repair request', ['all'], false, 'How do I ask for an emergency?', <<<'TXT'
In the **Priority** part of the form, choose the emergency tile. For most people it reads **Ask for emergency review**.

- Your request is still filed as a normal request.
- The maintenance office sees that you asked, and the reason you gave, in the ticket history. They decide whether to mark it as an emergency.
- Maintenance office staff and the School President can mark a ticket as an emergency directly.

If someone could be hurt, do not wait for the system: tell the maintenance office in person.
TXT, 40],

    ['Sending a repair request', ['all'], false, 'Can I attach photos or files?', <<<'TXT'
Yes. A photo often saves a trip.

- Up to **5 files** at a time, each up to **5 MB**.
- Allowed: jpg, jpeg, png, webp, pdf, docx and xlsx.
- The system checks what a file really is. Renaming a file to a different ending does not work.

If the form shows an error after you pressed **SEND REQUEST**, the browser forgets the files. Pick them again before you send.
TXT, 50],

    ['Sending a repair request', ['all'], false, 'What does "Mark as confidential" do?', <<<'TXT'
A confidential ticket can only be opened by:

- you,
- the maintenance staff assigned to it,
- the maintenance office, and
- the School President.

Other people, such as department heads and finance, cannot see it. The e-mails about it show only the ticket number, the status and a link, never the details.

Use it only when the problem is private.
TXT, 60],

    ['Sending a repair request', ['all'], false, 'How do I ask for materials, equipment or supplies?', <<<'TXT'
On the request form, choose a **Request type**: Materials, Equipment or Supplies. An **Items needed** part appears, where you list each item (up to 20 items).

If you only need something repaired, leave the Request type on **None (a repair or service)**.
TXT, 70],

    // ------------------------------------------------------------------ Following your request

    ['Following your request', ['all'], false, 'How do I know what is happening with my request?', <<<'TXT'
- Open **My Tickets** and choose your ticket. The page shows its **status**, a progress bar, and the **history** of everything that happened.
- Watch the **Notifications** bell. You are told when something changes.
- You may also get an e-mail. You are never e-mailed about something you did yourself.

You do not need to reload the ticket page: it refreshes by itself every few seconds (not while you are typing).
TXT, 10],

    ['Following your request', ['all'], true, 'What do the statuses mean?', <<<'TXT'
Every ticket has a status. This is what each one means:

- **Submitted**: we received it. Nobody has acted on it yet.
- **Pending Authorization**: it is waiting for the person who authorizes repair work.
- **Approved**: it was authorized. It is waiting for the office to choose who will do the work.
- **Assigned**: a maintenance staff member was chosen.
- **In Progress**: the staff member accepted the task and is working on it.
- **Work Completed**: the work is done. Now it is your turn: confirm it, or reopen it.
- **Closed**: finished.
- **Waiting for User**: the office asked you a question. Please answer it.
- **Escalated**: it was handed up to a higher person because it needs attention. The office handles it; you do not need to do anything.
- **Reopened**: it was sent back for more work.
- **Disapproved**: it will not be done. The reason is in the history.
- **Cancelled**: it was cancelled and will not be worked on.
TXT, 20],

    ['Following your request', ['all'], false, 'Where is my old or finished request?', <<<'TXT'
**My Tickets** shows the requests that are still being handled. When a request is finished, it moves to **Ticket History**. That is where Closed, Cancelled and Disapproved tickets are.
TXT, 30],

    ['Following your request', ['all'], false, 'When will my request be done?', <<<'TXT'
Every ticket has a **target date**. By default it is 5 days (120 hours) after you send a normal request, and 24 hours after an emergency. Hours are counted straight through, including nights and weekends.

The target date is a goal for the office, not a promise. It moves later by the time the ticket spent waiting for your answer. The **Date needed** you wrote is only your wish, and it does not change the target.
TXT, 40],

    ['Following your request', ['all'], false, 'The office asked me a question. How do I answer?', <<<'TXT'
The ticket is then **Waiting for User**.

1. Open the ticket.
2. Type your reply in the **Your answer (required)** box.
3. Press **Send answer**.

Only this box puts the ticket back to work. A normal message in the conversation does not. If nobody answers for 7 days, the ticket is cancelled automatically, so please answer soon.
TXT, 50],

    ['Following your request', ['all'], false, 'How do I cancel my request?', <<<'TXT'
You can cancel your own request **until it is assigned** to a staff member.

1. Open the ticket.
2. Type a reason.
3. Press **Cancel ticket** and confirm.

Cancelling cannot be undone. If the ticket is already assigned, tell the maintenance office instead, or write in the ticket conversation.
TXT, 60],

    ['Following your request', ['all'], false, 'The work is done. What do I do now?', <<<'TXT'
When the status is **Work Completed**, check the work.

- If it is fixed, press **Confirm resolved**. The ticket is closed.
- If something is still wrong, press **Reopen ticket** (see the next question).

Only the person who made the request can confirm it.
TXT, 70],

    ['Following your request', ['all'], false, 'How do I reopen a ticket?', <<<'TXT'
If the problem is not really fixed:

1. Open your ticket. It must be **Work Completed** or **Closed**.
2. In the **What is still wrong? (required)** box, explain.
3. Press **Reopen ticket**. The ticket goes back to work.

You can reopen only your own ticket, and only within 14 days after the work was completed or the ticket was closed. After that, the page says the reopen window has ended: send a new request instead.
TXT, 80],

];