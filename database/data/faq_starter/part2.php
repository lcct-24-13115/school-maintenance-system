<?php

/*
| FAQ starter content, part 2 (Phase 4D): Maintenance staff, Approvers and management, Preventive maintenance,
| Reports and records, Troubleshooting. This file replaces the temporary part2.php of 4C.
| Row = [topic, audience, public?, question, answer, sort_order]. See database/data/faq_starter.php.
| Audiences are real role slugs (config/permissions.php). Everything describes what the system really does today.
| Who authorizes follows config/school.php: workflow.authorizers = maintenance_admin.
*/

return [

    // ------------------------------------------------------------------ Maintenance staff

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I accept a task?', <<<'TXT'
Your dashboard opens the **maintenance board**. It has three columns: new tasks, tasks you are doing, and tasks done in the last 7 days.

1. Find the task in the new tasks column.
2. Press **ACCEPT TASK**. The ticket moves to **In Progress**.
3. When the work is finished, press **MARK COMPLETED**. The ticket moves to **Work Completed**.

You cannot accept a task that is assigned to someone else, or one that has not been authorized yet. A preventive maintenance (PM) task is completed on its own ticket page (see "How do I complete a preventive maintenance (PM) task?").
TXT, 10],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I assign a ticket to a staff member?', <<<'TXT'
1. Open the ticket. It must be **Approved** (or already Assigned, if you are changing who does it).
2. In the **Assign** panel, choose a person. The list shows the active maintenance staff and how many open tickets each one has.
3. Press the assign button. The ticket becomes **Assigned**.

To **reassign**, a note is required: say why the ticket is being moved. On the **Reports** page you can also assign a ticket that has nobody yet.
TXT, 20],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I ask the requester for more information?', <<<'TXT'
1. Open the ticket. In the **Ask the requester for information** panel, type what you need to know (required).
2. Press **Ask the requester**. The ticket becomes **Waiting for User** and the requester is told.

While it waits, the ticket's target time is paused. The requester answers in the **Your answer** box. If nobody answers for 7 days, the ticket is cancelled automatically.
TXT, 30],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I flag an emergency or escalate a ticket?', <<<'TXT'
- **Flag emergency**: a reason is required. The ticket is moved to the front of the queue and its target time becomes shorter. **Clear emergency** takes the flag off (a note is optional). You cannot flag a Closed or Disapproved ticket.
- **Escalate ticket**: a reason is required. It hands the ticket up to the President, and the President and the Maintenance Admin are alerted. You cannot escalate a ticket that is Waiting for User, or one that is already escalated.

Tickets that are past their target date are also escalated automatically, once an hour.

Right now the ticket page has no button that sends an **Escalated** ticket back to work. Escalated tickets are handled by the office outside the system.
TXT, 40],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I complete a preventive maintenance (PM) task?', <<<'TXT'
Open the PM ticket that is **In Progress**. In the Preventive Maintenance card:

1. Choose the **Date completed**. It cannot be in the future.
2. Choose the **Technician** (active maintenance staff).
3. Tick each line of the **Checklist**. A line you did not tick needs a note that explains why.
4. Add **Notes** if you want (optional).
5. Press **Mark PM completed**.

The ticket moves to **Work Completed**, and the result is kept on the ticket.
TXT, 50],

    ['Maintenance staff', ['maintenance_admin'], false, 'Who closes a ticket?', <<<'TXT'
The person who made the request. When a ticket is **Work Completed**, the requester checks the work and presses **Confirm resolved**, and the ticket becomes **Closed**. If the requester finds the problem is not fixed, they can reopen it within 14 days.

There is no "Close" button for staff on the ticket page. A ticket can only be closed after someone has authorized it.
TXT, 60],

    // ------------------------------------------------------------------ Approvers and management

    ['Approvers and management', ['all'], false, 'Who approves a repair request?', <<<'TXT'
The maintenance office authorizes repair requests. The ticket records who authorized it and when, as **Authorized by**. If a request is refused, the reason is written in the ticket history.

The system records the approval. It does not replace the school's official signatures.
TXT, 10],

    ['Approvers and management', ['maintenance_admin'], false, 'How do I approve or disapprove a ticket?', <<<'TXT'
1. Choose **Approvals** in the menu. It lists the tickets waiting for authorization: Pending Authorization, and also Submitted ones. Emergencies come first, then the oldest request.
2. To approve, add remarks if you want, and attach the signed file if you have one (both optional). Press **Approve**. The ticket becomes **Approved**.
3. To refuse, write the reason (required) and press **Disapprove**. A disapproved ticket is final.

You can also approve or disapprove from the ticket page. The Approvals page is not refreshed by itself, so reload it to see new tickets.
TXT, 20],

    ['Approvers and management', ['department_head', 'finance', 'president'], false, 'What can I see and do with my role?', <<<'TXT'
- **Department head**: you see your own tickets and the non-confidential tickets of your department. You can look, but you cannot add notes or send replies on other people's tickets.
- **Finance**: you see all tickets that are not confidential. You can open **Reports**, download the PDF, and open the **PM report**. You do not see PM schedules or the PM calendar.
- **President**: you see all tickets, including confidential ones. You can flag an emergency, open **Reports**, the **Audit log**, the **PM report**, and look at the **PM schedules** and the **PM calendar** (look only, no changes).

The office may change what a role can do. If you need something you cannot see, ask the maintenance office.
TXT, 30],

    // ------------------------------------------------------------------ Preventive maintenance

    ['Preventive maintenance', ['maintenance_admin', 'president'], false, 'How does preventive maintenance (PM) work?', <<<'TXT'
A **PM schedule** repeats a job that must be done again and again, for example cleaning the aircon every quarter.

- Every day at 1:00 AM (Manila time) the system looks at the schedules. A few days before a job is due, it makes a ticket by itself. How many days before is set in the schedule ("lead time").
- The ticket looks like "[PM] Aircon Cleaning - Q4 2026". It is already **Approved**, and already **Assigned** when the schedule has a person.
- If the system missed some days, it makes only one ticket, not many.
- Press **Ticket now** on a schedule to make the ticket right away.
- Reminders go out 3 days before the due date. A late job is marked overdue, and the President is alerted when it is 2 days late.

The President can look at schedules but only the Maintenance Admin can change them.
TXT, 10],

    ['Preventive maintenance', ['maintenance_admin'], false, 'How do I make a PM schedule?', <<<'TXT'
1. Choose **PM schedules** in the menu, then **New schedule**.
2. Fill in the **Title** and the **Facility**. Add the **Equipment** if the job is about one machine (optional).
3. In **Repeats**, say how often: daily, weekly, monthly, quarterly, yearly, or every N days.
4. Set the **Start date** (the first due date) and, if it ends, the **End date**.
5. Set **Make the ticket this many days before the due date**, the **Ticket priority**, and who it is **Assigned to**.
6. Choose **Next due date is based on**: **Fixed** (the dates always follow the original calendar) or **Floating** (the next date is counted from the day the work was really completed).
7. Write the **Checklist**, one task per line (optional). It is copied into every ticket.
8. Save.
TXT, 20],

    ['Preventive maintenance', ['maintenance_admin', 'president'], false, 'What do the colours of the PM calendar mean?', <<<'TXT'
Open **PM calendar** in the menu.

- **Blue**: upcoming.
- **Yellow**: open or in progress.
- **Green**: completed.
- **Red**: overdue.
- **Grey**: cancelled or disapproved.

You can filter by month, facility, equipment, category, who it is assigned to, and status.
TXT, 30],

    ['Preventive maintenance', ['maintenance_admin', 'president', 'finance'], false, 'How do I read the PM report?', <<<'TXT'
Open **PM report** in the menu.

- **On time**: done on or before the due date.
- **Late**: done after the due date.
- **Overdue**: still open and past the due date.
- **On-time rate** = on time divided by (on time + late + overdue). Jobs that are not due yet, and cancelled ones, are left out.

Use **Download CSV** for all rows, or the PDF (the latest 300 rows). Every download is recorded in the audit log.
TXT, 40],

    // ------------------------------------------------------------------ Reports and records

    ['Reports and records', ['maintenance_admin', 'finance', 'president'], false, 'What does the Reports page show?', <<<'TXT'
Open **Reports** in the menu.

- Choose a period with the quick buttons, or with **From (submitted)** and **To (submitted)**, then press **Apply**.
- You see the number of tickets, emergencies, overdue tickets, the backlog, the resolution rate, charts, **Tickets per day**, the **Workload per assigned staff**, and the **Needs attention** list.
- Only the tickets you are allowed to see are counted. Finance never sees confidential tickets in the numbers.
- **Download PDF** makes a copy of the report. Every export is written to the audit log.
TXT, 10],

    ['Reports and records', ['maintenance_admin', 'finance', 'president'], false, 'What do the report numbers mean?', <<<'TXT'
- **Backlog**: tickets still waiting for work to finish (Submitted up to In Progress, Waiting for User, Escalated and Reopened). **Work Completed** is not backlog: the work is done and it only waits to be closed.
- **Resolution rate**: Work Completed plus Closed, divided by all tickets except Cancelled. Disapproved tickets stay in the total.
- **First response**: the time from sending the request to the first action by staff, or the first public message from someone other than the requester. Internal notes and automatic actions do not count.
- **Needs attention**: open tickets that are overdue, that have nobody assigned, or that had no activity for 3 days. The list shows the 25 most urgent.
TXT, 20],

    ['Reports and records', ['maintenance_admin', 'president'], false, 'What is the audit log?', <<<'TXT'
Open **Audit log** in the menu. It is a read-only record of who did what and when, for example approvals, exports and changes to the FAQ. Nobody can change or delete a line.

Use the filters **User**, **Action**, **From**, **To** and **Search** to find what you need.
TXT, 30],

    // ------------------------------------------------------------------ Troubleshooting

    ['Troubleshooting', ['all'], false, 'I cannot open a ticket or a page. Why?', <<<'TXT'
- **"Not found" (404) on a ticket**: you are not allowed to see that ticket. You see your own tickets, the ones assigned to you, and what your role allows. Confidential tickets are hidden from most people.
- **"Forbidden" (403)**: you are signed in, but your role does not use that page or button.

If you think you should have access, tell the maintenance office the ticket number or the page.
TXT, 10],

    ['Troubleshooting', ['all'], false, 'The page says "Page Expired" (419) or signed me out. What do I do?', <<<'TXT'
You stay signed in for about 2 hours. After that the system asks you to sign in again, and a form you left open may say **Page Expired**.

1. Sign in again.
2. Open the page again and repeat what you were doing.

If you are writing a long text, copy it before you press send.
TXT, 20],

    ['Troubleshooting', ['all'], false, 'I am not getting e-mails. Why?', <<<'TXT'
- Check your spam folder.
- You are never e-mailed about something you did yourself.
- E-mails about a confidential ticket show only the ticket number, the status and a link.
- Internal notes are never e-mailed.
- E-mails are sent in the background. If they stop for everyone, tell the maintenance office: the e-mail service may be off.

The **Notifications** bell in the system always shows the news, even without e-mail.
TXT, 30],

    ['Troubleshooting', ['all'], false, 'My file will not upload. Why?', <<<'TXT'
Check these:

- No more than **5 files** at a time, each up to **5 MB**.
- Only jpg, jpeg, png, webp, pdf, docx and xlsx.
- A file renamed to look like another type is refused, and so is a name like "file.php.jpg".
- After a form error, the browser forgets the files. Pick them again.
TXT, 40],

    ['Troubleshooting', ['all'], false, 'I cannot answer, cancel or reopen my ticket. Why?', <<<'TXT'
- Only the person who made the request can answer, cancel or reopen it.
- You can **answer** only while the ticket is **Waiting for User**.
- You can **cancel** only before the ticket is assigned.
- You can **reopen** only a Work Completed or Closed ticket, within 14 days.
- If the page says "This ticket was just updated by someone else", someone changed the ticket while you were looking at it. Reload the page and try again.
TXT, 50],

];