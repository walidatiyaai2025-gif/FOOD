<?php

namespace App\Enterprise\Search;

use App\Models\User;

interface GlobalSearchProvider
{
    /**
     * @return list<array{type:string,type_label:string,title:string,subtitle:string,url:string}>
     */
    public function search(User $actor, string $query, int $limit): array;
}
