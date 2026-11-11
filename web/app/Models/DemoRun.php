<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One "Try the demo" (decision 44), kept after the demo studio is deleted so demos can be counted,
 * and whether that browser went on to start a real studio (studio_id). Nothing about the visitor.
 */
#[Fillable(['studio_id', 'converted_at'])]
class DemoRun extends Model
{
    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }
}
