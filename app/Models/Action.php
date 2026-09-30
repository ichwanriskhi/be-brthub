<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Action extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Kategori-kategori yang menggunakan action ini.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_actions')
            ->withPivot('is_recommended')
            ->withTimestamps();
    }
}
