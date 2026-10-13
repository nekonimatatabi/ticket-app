<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    /**
     * 注文した本人、そのイベントの主催者、管理者だけが見られる。それ以外には 404（存在しない扱い）を返す。
     */
    public function view(User $user, Order $order): Response|bool
    {
        if ($user->isAdmin()
            || $order->user_id === $user->id
            || $order->event->organizer_id === $user->id) {
            return true;
        }

        return Response::denyAsNotFound();
    }

    /**
     * 支払いを中断できるのは、注文した本人だけ。それ以外には 404（存在しない扱い）を返す。
     */
    public function cancel(User $user, Order $order): Response|bool
    {
        if ($order->user_id === $user->id) {
            return true;
        }

        return Response::denyAsNotFound();
    }
}
