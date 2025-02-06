<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class ProductType extends Model
{
    protected $fillable = [
        'name',
      ];
}
