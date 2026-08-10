<?php

use Livewire\Component;
use App\Actions\AnswerQuestion;

new class extends Component {
    public string $question = "";
    public ?string $answer = null;

    public function ask()
    {
        $this->answer = AnswerQuestion::handle($this->question);

    }

};
?>

<div>

    <form wire:submit="ask">
        <input type="text" wire:model="question">
        <button type="submit">Submit</button>
    </form>

    <div wire:loading>Razmišljam...</div>

    @if ($answer)
        <p>{{ $answer }}</p>
    @endif

</div>