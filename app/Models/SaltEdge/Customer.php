<?php

namespace App\Models\SaltEdge;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'salt_edge_customers';
    protected $fillable = ['customer_id', 'provider', 'object', 'hash'];
}
