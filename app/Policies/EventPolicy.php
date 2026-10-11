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

    public function delete(User $user, Event $event): bool
    {
        return $this->isOwner($user, $event);
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
