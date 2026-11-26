<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $svc) {}

    public function index(Request $request)
    {
        $unreadOnly = $request->query('filter') === 'unread';

        return view('notify.index', [
            'items' => $this->svc->list($request->user(), $unreadOnly),
            'unreadOnly' => $unreadOnly,
        ]);
    }

    public function read(Request $request, int $id)
    {
        $n = $this->svc->markRead($request->user(), $id);
        abort_unless($n, 404);

        return $n->url ? redirect()->to(url('/'.ltrim($n->url, '/'))) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request)
    {
        $this->svc->markAllRead($request->user());

        return redirect()->route('notifications.index')->with('ok', '已全部標示為已讀。');
    }
}
