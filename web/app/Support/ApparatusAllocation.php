<?php

namespace App\Support;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The apparatus a class uses, from the program and session forms: apparatus[{id}][quantity] and
 * apparatus[{id}][max_per_item] for each apparatus in the chosen room. Inputs for other rooms are
 * ignored. A class can't use more than the room has, or put more people on an item than the rig allows.
 */
class ApparatusAllocation
{
    /**
     * @return array<int, array{quantity: int, max_per_item: int}> ready for sync() on the apparatus relation
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, ?Room $room): array
    {
        if (! $room) {
            return [];
        }
        $rules = [];
        $attributes = [];
        foreach ($room->apparatus as $a) {
            $rules["apparatus.{$a->id}.quantity"] = ['nullable', 'integer', 'min:0', "max:{$a->quantity}"];
            $rules["apparatus.{$a->id}.max_per_item"] = ['nullable', 'integer', 'min:1', "max:{$a->max_per_item}"];
            $attributes["apparatus.{$a->id}.quantity"] = $a->name;
            $attributes["apparatus.{$a->id}.max_per_item"] = "people on each {$a->name}";
        }
        $data = $request->validate($rules, [
            'apparatus.*.quantity.max' => ':Attribute: the room has :max.',
            'apparatus.*.max_per_item.max' => 'At most :max on each, the rig’s limit.',
        ], $attributes);

        $allocation = [];
        foreach ($room->apparatus as $a) {
            $quantity = (int) data_get($data, "apparatus.{$a->id}.quantity", 0);
            if ($quantity > 0) {
                $allocation[$a->id] = [
                    'quantity' => $quantity,
                    'max_per_item' => (int) (data_get($data, "apparatus.{$a->id}.max_per_item") ?: $a->max_per_item),
                ];
            }
        }

        return $allocation;
    }

    /** The capacity rule: no more than the room's fire-code limit. */
    public static function capacityRule(?Room $room): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($room) {
            if ($room && (int) $value > $room->max_capacity) {
                $fail("{$room->name} holds at most {$room->max_capacity} people.");
            }
        };
    }
}
