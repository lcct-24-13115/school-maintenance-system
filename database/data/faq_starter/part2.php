<?php

/*
| FAQ starter content, part 2. TEMPORARY: these two sample rows keep the old tests green until Phase 4D replaces
| this whole file with the full "Maintenance staff, Approvers and management, Preventive maintenance, Reports and
| records, Troubleshooting" content (4D keeps these two questions, so nothing is added twice).
*/

return [

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I accept a task?', <<<'TXT'
Open the ticket that is assigned to you and accept the task. The ticket moves to **In Progress**.
TXT, 10],

    ['Maintenance staff', ['maintenance_admin'], false, 'How do I complete a preventive maintenance (PM) task?', <<<'TXT'
Open the PM ticket that is In Progress and fill in the Complete PM form: the date, the technician, the checklist and your notes. The ticket then moves to **Work Completed**.
TXT, 20],

];