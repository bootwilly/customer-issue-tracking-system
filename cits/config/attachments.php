<?php

// Permission a project member needs to open a bound attachment, by the container it belongs to (attachments.owner_type).
// An owner type missing here is never served to non-admins.
return [
    'view_permissions' => [
        'ticket' => 'ticket.view',
        'ticket_comment' => 'ticket.view',
        'issue' => 'issue.view',
        'issue_comment' => 'issue.view',
        'meeting' => 'meeting.view',
        'meeting_internal' => 'meeting.view',
        'meeting_item' => 'meeting.view',
    ],
];
