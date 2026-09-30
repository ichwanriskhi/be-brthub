<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_category_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    /**
     * Aksi-aksi handler yang tersedia untuk kategori ini.
     */
    public function actions(): BelongsToMany
    {
        return $this->belongsToMany(Action::class, 'category_actions')
            ->withPivot('is_recommended')
            ->withTimestamps();
    }
}
