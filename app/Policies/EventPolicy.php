<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    /**
     * 公開中なら誰でも。それ以外は所有者と管理者だけで、見られない人には 404（存在しない扱い）を返す。
     */
    public function view(?User $user, Event $event): Response|bool
    {
        if ($event->isPublished()) {
            return true;
        }

        if ($user !== null && ($user->isAdmin() || $this->isOwner($user, $event))) {
            return true;
        }

        return Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    public function update(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
    }

    /**
     * 所有者だけが、注文が1件もないイベントを削除できる。注文は売上の記録で、外部キー（restrict）でも消せない。
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event) && ! $event->orders()->exists();
    }

    /**
     * イベントの売上と注文の一覧は、所有者と管理者だけが見られる。
     */
    public function viewOrders(User $user, Event $event): bool
    {
        return $user->isAdmin() || $this->isOwner($user, $event);
    }

    public function unpublish(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }

    private function isOwner(User $user, Event $event): bool
    {
        return $event->organizer_id === $user->id;
    }
}
