<?php

namespace App\Http\Controllers;

use App\Services\WansisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SapController extends Controller
{
    public function __construct(
        private readonly WansisService $wansisService,
    ) {}

    /**
     * Cari master data item dari SAP untuk searchable dropdown
     *
     * GET /api/sap/items?q={keyword}&page={page}
     */
    public function searchItems(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = $validated['q'];
        $page = $validated['page'] ?? 1;

        try {
            $items = $this->wansisService->searchSapItems($query, $page);

            return response()->json([
                'success' => true,
                'data' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data SAP items',
            ], 500);
        }
    }
}
