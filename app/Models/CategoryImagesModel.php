<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryImagesModel extends Model
{
    // Explicitly define table name if it doesn't follow Laravel convention
    protected $table = 'category_images_models';

    // Mass-assignable fields
    protected $fillable = [
        'category_id',
        'image_path',
    ];

    // Automatically eager load the category relationship
    protected $with = ['category'];

    /**
     * Relationship: Each image belongs to one category
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
    public function images()
{
    return $this->hasMany(CategoryImagesModel::class, 'category_id');
}

}
