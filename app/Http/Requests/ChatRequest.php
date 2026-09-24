<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Ai\Models\Conversation;

class ChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** The question to answer from the knowledge base. */
            'question' => ['required', 'string', 'max:500'],
            /** ID of one of your conversations to continue. Omit it to start a new conversation. */
            'conversation_id' => [
                'nullable',
                'string',
                Rule::exists(Conversation::class, 'id')
                    ->where('participant_type', $this->user()?->getMorphClass())
                    ->where('participant_id', $this->user()?->id),
            ],
        ];
    }
}
