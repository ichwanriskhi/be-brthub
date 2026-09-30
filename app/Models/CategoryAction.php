<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAction extends Model
{
    protected $table = 'category_actions';

    protected $fillable = ['category_id', 'action_id', 'is_recommended'];

    protected $casts = [
        'is_recommended' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }
}
