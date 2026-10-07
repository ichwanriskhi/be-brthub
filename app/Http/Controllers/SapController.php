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
     * GET /api/auth/sap/items?q={keyword}&page={page}
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

    /**
     * Ambil detail Sales Order dari SAP berdasarkan nomor SO.
     * Response langsung diteruskan ke FE (lines, header, dsb).
     *
     * GET /api/auth/sap/order/detail?soNumber={so}
     */
    public function getOrderDetail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'soNumber' => 'required|string|min:1|max:50',
        ]);

        try {
            $data = $this->wansisService->getOrderDetail($validated['soNumber']);

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'SO tidak ditemukan atau SAP tidak dapat dihubungi.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail SO dari SAP.',
            ], 500);
        }
    }

    /**
     * Daftar Item Group SAP untuk dropdown & mapping kode grup.
     *
     * GET /api/auth/sap/item-groups
     */
    public function itemGroups(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->wansisService->getItemGroups(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil SAP item groups.',
            ], 500);
        }
    }

    /**
     * Ambil daftar Sales Order untuk dropdown SoCombobox.
     *
     * GET /api/auth/sap/order/monitor-list
     */
    public function getSoMonitorList(): JsonResponse
    {
        try {
            $data = $this->wansisService->getSoMonitorList();

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil daftar SO dari SAP.',
                ], 502);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar SO dari SAP.',
            ], 500);
        }
    }
}
