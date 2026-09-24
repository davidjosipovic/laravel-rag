<?php

namespace App\Policies;

use App\Models\User;
use Laravel\Ai\Models\Conversation;

class ConversationPolicy
{
    /**
     * Determine whether the user can view the conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->ownsConversation($user, $conversation);
    }

    /**
     * Determine whether the user can delete the conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->ownsConversation($user, $conversation);
    }

    private function ownsConversation(User $user, Conversation $conversation): bool
    {
        return $conversation->participant_type === $user->getMorphClass()
            && $conversation->participant_id === $user->id;
    }
}
