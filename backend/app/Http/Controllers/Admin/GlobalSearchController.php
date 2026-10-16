<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Search\GlobalSearchRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchRegistry $search): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);

        $query = trim((string) $validated['q']);
        if (mb_strlen($query) < 2) {
            return $this->response($query, [], (int) ($validated['limit'] ?? 5));
        }

        $limit = (int) ($validated['limit'] ?? 5);
        $results = $search->search($actor, $query, $limit);

        return $this->response($query, $results, $limit);
    }

    /** @param list<array<string, string>> $results */
    private function response(string $query, array $results, int $limit): JsonResponse
    {
        return response()->json([
            'data' => $results,
            'meta' => [
                'query' => $query,
                'count' => count($results),
                'limit_per_category' => $limit,
            ],
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
