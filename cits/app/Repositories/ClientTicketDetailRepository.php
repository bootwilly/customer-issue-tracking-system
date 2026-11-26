<?php

namespace App\Repositories;

use App\Models\ClientTicket;

class ClientTicketDetailRepository
{
    public function load(ClientTicket $t): ClientTicket
    {
        return $t->load([
            'creator:id,name,account', 'mesPage:id,name,page_file',
            'revisions.author:id,name', 'comments.author:id,name,user_type', 'reopens.author:id,name',
        ]);
    }
}
