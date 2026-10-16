<?php

namespace App\Livewire\Forms;

use App\Models\Event;
use Livewire\Form;

class EventForm extends Form
{
    public string $title = '';

    public string $description = '';

    public string $venue = '';

    public string $starts_at = '';

    public string $ends_at = '';

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'venue' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'タイトル',
            'description' => '説明',
            'venue' => '会場',
            'starts_at' => '開始日時',
            'ends_at' => '終了日時',
        ];
    }

    /**
     * 既存のイベントの値をフォームに入れる。日時は datetime-local の形式にする。
     */
    public function fillFromEvent(Event $event): void
    {
        $this->title = $event->title;
        $this->description = (string) $event->description;
        $this->venue = $event->venue;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $event->ends_at->format('Y-m-d\TH:i');
    }
}
