<?php

namespace App\Livewire\Forms;

use App\Models\TicketType;
use Livewire\Form;

class TicketTypeForm extends Form
{
    public string $name = '';

    /**
     * 数値の入力欄を空にすると、空文字や null が入る。int だけだとその瞬間に TypeError になるため、
     * int|string|null で持ち、検証を通ったあとに (int) へキャストする。
     */
    public int|string|null $price = '';

    public int|string|null $capacity = '';

    public string $sales_starts_at = '';

    public string $sales_ends_at = '';

    /**
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'sales_starts_at' => ['required', 'date'],
            'sales_ends_at' => ['required', 'date', 'after:sales_starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => '券種名',
            'price' => '価格',
            'capacity' => '定員',
            'sales_starts_at' => '販売開始',
            'sales_ends_at' => '販売終了',
        ];
    }

    /**
     * 既存の券種の値をフォームに入れる。日時は datetime-local の形式にする。
     */
    public function fillFromTicketType(TicketType $ticketType): void
    {
        $this->name = $ticketType->name;
        $this->price = $ticketType->price;
        $this->capacity = $ticketType->capacity;
        $this->sales_starts_at = $ticketType->sales_starts_at->format('Y-m-d\TH:i');
        $this->sales_ends_at = $ticketType->sales_ends_at->format('Y-m-d\TH:i');
    }

    /**
     * 検証して、保存用の値を返す。価格と定員は整数にキャストする。
     *
     * 定員の下限（すでに販売・確保された枚数）は、画面から書き換えられないよう、呼び出し側が DB から求めて渡す。
     *
     * @param  int  $minimumCapacity  定員の下限。新しい券種では 1
     * @return array{name: string, price: int, capacity: int, sales_starts_at: string, sales_ends_at: string}
     */
    public function validatedPayload(int $minimumCapacity = 1): array
    {
        $minimumCapacity = max(1, $minimumCapacity);

        $rules = $this->rules();
        $rules['capacity'] = ['required', 'integer', "min:{$minimumCapacity}", 'max:100000'];

        $messages = $minimumCapacity > 1
            ? ['capacity.min' => "定員は、販売済み・確保中の枚数（{$minimumCapacity}枚）より少なくできません。"]
            : [];

        $validated = $this->validate($rules, $messages);

        return [
            'name' => $validated['name'],
            'price' => (int) $validated['price'],
            'capacity' => (int) $validated['capacity'],
            'sales_starts_at' => $validated['sales_starts_at'],
            'sales_ends_at' => $validated['sales_ends_at'],
        ];
    }
}
