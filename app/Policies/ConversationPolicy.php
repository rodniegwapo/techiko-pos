<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperUser();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        if ($user->isSuperUser()) {
            return true;
        }

        return (int) $user->id === (int) $conversation->user_id;
    }

    public function sendStaffMessage(User $user, Conversation $conversation): bool
    {
        return $user->isSuperUser();
    }

    public function markAsReadByStaff(User $user, Conversation $conversation): bool
    {
        return $user->isSuperUser();
    }
}
