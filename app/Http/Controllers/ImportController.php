<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ImportServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * @OA\Post(
 *     path="/api/imports",
 *     summary="Upload and import invoices from Excel (Admin)",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 @OA\Property(property="file", type="string", format="binary")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=201, description="Import created"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 */
class ImportController extends Controller
{
    protected $importService;

    public function __construct(ImportServiceInterface $importService)
    {
        $this->importService = $importService;
    }

    // Admin: Insert import (upload)
    public function store(Request $request)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $userId = Auth::id();
        $result = $this->importService->importFromExcel($request->file('file'), $userId);
        return response()->json($result, Response::HTTP_CREATED);
    }

    /**
     * @OA\Put(
     *     path="/api/imports/{id}",
     *     summary="Update an import (Admin)",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="file", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Import updated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    // Admin: Update import
    public function update(Request $request, $id)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|string',
            'file' => 'sometimes|file|mimes:xlsx,xls',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $data = $request->all();
        if ($request->hasFile('file')) {
            $data['file'] = $request->file('file');
        }
        try {
            $result = $this->importService->updateImport($id, $data);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/imports/{id}",
     *     summary="Delete an import (Admin)",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Import deleted")
     * )
     */
    // Admin: Delete import
    public function destroy($id)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        try {
            $this->importService->deleteImport($id);
            return response()->json(['message' => 'Import deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/imports",
     *     summary="List all imports (Admin, paginated)",
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="List of imports")
     * )
     */
    // Admin: View all imports (paginated)
    public function index(Request $request)
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $perPage = $request->get('per_page', 15);
        $imports = $this->importService->getAllImports($perPage);
        return response()->json($imports);
    }

    /**
     * @OA\Get(
     *     path="/public/imports",
     *     summary="List all imports (Public, paginated)",
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="List of imports")
     * )
     */
    // Public: Read (view all imports, paginated)
    public function publicIndex(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $imports = $this->importService->getAllImports($perPage);
        return response()->json($imports);
    }
} 